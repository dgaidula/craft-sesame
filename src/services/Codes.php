<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\models\Code;
use iceboxind\sesame\Plugin;

/**
 * Named codes — a rule's credentials ({{%sesame_rule_codes}}). A rule's password
 * is "code one" (earliest by dateCreated); Pro adds more, each with a label,
 * optional expiry, and individual revoke, and its own shareable magic link.
 *
 * This is the WHOLE surface the CP code-management UI calls — add / relabel /
 * setExpiry / changePassword / revoke / delete / forRule / codeOne — so the UI
 * pass adds screens and reaches around nothing. Enforcement (verify, the gate,
 * isUnlocked's code-active check) reads through {@see activeForRule()} /
 * {@see getByUid()} and is never edition-gated; authoring is gated in the
 * controllers.
 *
 * Two revocation levels: a per-code revoke/expiry cuts off just that code's
 * holders (the codeId carried in the session/cookie/link stops being active);
 * changing a code's password bumps the whole RULE epoch (P0.2) — every code's
 * outstanding unlocks are cut, matching "changing a password locks everyone out".
 */
class Codes extends Component
{
    /** Max codes per rule — caps the per-attempt verify loop (N bcrypts in hash mode). */
    public const MAX_CODES = 25;

    /** @return Code[] All of a rule's codes, oldest first (code one leads). */
    public function forRule(string $ruleUid): array
    {
        return array_map(
            [$this, 'rowToCode'],
            $this->baseQuery()->where(['ruleUid' => $ruleUid])->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])->all()
        );
    }

    /** @return Code[] Only the codes that currently grant access (not revoked, not expired), capped. */
    public function activeForRule(string $ruleUid): array
    {
        $active = array_filter($this->forRule($ruleUid), static fn(Code $c) => $c->isActive());
        return array_slice(array_values($active), 0, self::MAX_CODES);
    }

    public function getByUid(string $codeUid): ?Code
    {
        $row = $this->baseQuery()->where(['uid' => $codeUid])->one();
        return $row ? $this->rowToCode($row) : null;
    }

    /** The rule's password: the earliest code. Null if the rule somehow has none. */
    public function codeOne(string $ruleUid): ?Code
    {
        $row = $this->baseQuery()->where(['ruleUid' => $ruleUid])->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])->one();
        return $row ? $this->rowToCode($row) : null;
    }

    /** Whether the code with this uid still grants access (missing/deleted = no). */
    public function isActive(string $codeUid): bool
    {
        return $this->getByUid($codeUid)?->isActive() ?? false;
    }

    public function count(string $ruleUid): int
    {
        return (int) $this->baseQuery()->where(['ruleUid' => $ruleUid])->count();
    }

    /**
     * Encodes a raw password and adds a code to the rule. Returns null if the raw
     * password is blank or the rule is already at MAX_CODES. No epoch bump — a
     * brand-new code has no outstanding unlocks to revoke.
     */
    public function add(string $ruleUid, string $rawPassword, ?string $label = null, ?string $expiresAt = null): ?Code
    {
        if ($rawPassword === '' || $this->count($ruleUid) >= self::MAX_CODES) {
            return null;
        }

        $encoded = Plugin::getInstance()->secrets->store($rawPassword);
        $now = Db::prepareDateForDb(new \DateTime());
        $uid = StringHelper::UUID();

        Craft::$app->getDb()->createCommand()->insert(Install::RULE_CODES_TABLE, [
            'ruleUid' => $ruleUid,
            'label' => $label,
            'secret' => $encoded['secret'],
            'secretMode' => $encoded['mode'],
            'expiresAt' => $expiresAt,
            'revokedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $uid,
        ])->execute();

        return $this->getByUid($uid);
    }

    /**
     * Re-encodes a code's password and bumps the OWNING RULE's epoch, cutting off
     * every outstanding unlock for the rule (a password change locks everyone
     * out, P0.2). To cut off just one code's holders WITHOUT changing a password,
     * use {@see revoke()} instead. Blank password is a no-op.
     */
    public function changePassword(string $codeUid, string $rawPassword): bool
    {
        if ($rawPassword === '') {
            return false;
        }
        $code = $this->getByUid($codeUid);
        if ($code === null) {
            return false;
        }

        $encoded = Plugin::getInstance()->secrets->store($rawPassword);
        $this->update($codeUid, ['secret' => $encoded['secret'], 'secretMode' => $encoded['mode']]);
        Plugin::getInstance()->rules->revoke($code->ruleUid); // bump the rule epoch
        return true;
    }

    public function relabel(string $codeUid, ?string $label): bool
    {
        return $this->update($codeUid, ['label' => $label]);
    }

    /** @param ?string $expiresAt UTC datetime string, or null to clear the expiry. */
    public function setExpiry(string $codeUid, ?string $expiresAt): bool
    {
        return $this->update($codeUid, ['expiresAt' => $expiresAt]);
    }

    /**
     * Cuts off just this code's holders: stamps revokedAt now, so every session /
     * remember-me cookie / magic link carrying this codeId stops being active.
     * No rule-wide epoch bump — other codes are untouched.
     */
    public function revoke(string $codeUid): bool
    {
        return $this->update($codeUid, ['revokedAt' => Db::prepareDateForDb(new \DateTime())]);
    }

    /**
     * Hard-deletes a code — same access effect as revoke (its codeId stops
     * resolving), but the row is gone. Refuses to delete a rule's LAST code: a
     * rule must always keep code one (its password), or it could never be
     * unlocked at all.
     */
    public function delete(string $codeUid): bool
    {
        $code = $this->getByUid($codeUid);
        if ($code === null || $this->count($code->ruleUid) <= 1) {
            return false;
        }

        Craft::$app->getDb()->createCommand()->delete(Install::RULE_CODES_TABLE, ['uid' => $codeUid])->execute();
        return true;
    }

    /** Decrypts a code's password for CP display — encrypt mode only; null for hash/failure. */
    public function reveal(string $codeUid): ?string
    {
        $code = $this->getByUid($codeUid);
        return $code ? Plugin::getInstance()->secrets->reveal($code->secret, $code->secretMode) : null;
    }

    private function update(string $codeUid, array $data): bool
    {
        $data['dateUpdated'] = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->update(Install::RULE_CODES_TABLE, $data, ['uid' => $codeUid])->execute();
        return true;
    }

    private function baseQuery(): Query
    {
        // dateCreated is selected only for ordering ("code one" = earliest); it
        // isn't mapped onto the model (Craft would auto-cast it to a DateTime,
        // clashing with a typed ?string property).
        return (new Query())
            ->select(['id', 'ruleUid', 'label', 'secret', 'secretMode', 'expiresAt', 'revokedAt', 'dateCreated', 'uid'])
            ->from(Install::RULE_CODES_TABLE);
    }

    private function rowToCode(array $row): Code
    {
        return new Code([
            'id' => (int) $row['id'],
            'uid' => (string) $row['uid'],
            'ruleUid' => (string) $row['ruleUid'],
            'label' => $row['label'],
            'secret' => (string) $row['secret'],
            'secretMode' => (string) $row['secretMode'],
            'expiresAt' => $row['expiresAt'] ?? null,
            'revokedAt' => $row['revokedAt'] ?? null,
        ]);
    }
}
