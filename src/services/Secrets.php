<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\Plugin;
use yii\db\Expression;

/**
 * Owns every secret Sesame stores: the generic encode/verify/reveal contract
 * (used by both rules and per-entry passwords), and the CRUD for
 * {{%sesame_entry_secrets}} — the per-entry (Protect field) password table,
 * keyed by the owning element's UID.
 *
 * Encoding (see PATTERNS.md §4): encrypt-at-rest by default via
 * `Security::encryptByKey()`/`decryptByKey()` + `hash_equals()` — reversible,
 * so an admin can reveal/re-share a shared page password later. bcrypt
 * (`hashPassword()`/`validatePassword()`, write-only) when
 * `settings.hashPasswords` is true.
 */
class Secrets extends Component
{
    // --- Generic encode / verify / reveal ---

    /** @return array{secret:string,mode:'encrypt'|'hash'} */
    public function store(string $raw): array
    {
        $security = Craft::$app->getSecurity();

        if (Plugin::getInstance()->getSettings()->hashPasswords) {
            return ['secret' => $security->hashPassword($raw), 'mode' => 'hash'];
        }

        // encryptByKey() returns raw binary; base64 it so it stores cleanly in
        // the utf8 `secret` text column (raw binary throws an "Incorrect string
        // value" on insert). reveal() base64-decodes before decrypting.
        return ['secret' => base64_encode($security->encryptByKey($raw)), 'mode' => 'encrypt'];
    }

    public function verify(string $raw, string $stored, string $mode): bool
    {
        if ($stored === '' || $raw === '') {
            return false;
        }

        if ($mode === 'hash') {
            try {
                return Craft::$app->getSecurity()->validatePassword($raw, $stored);
            } catch (\Throwable $e) {
                // A malformed/foreign hash must fail closed, not throw.
                return false;
            }
        }

        $decrypted = $this->reveal($stored, $mode);
        return $decrypted !== null && hash_equals($decrypted, $raw);
    }

    /** Decrypts for CP display when `mode === 'encrypt'`; null for `hash` (write-only) or on failure. */
    public function reveal(string $stored, string $mode): ?string
    {
        if ($mode !== 'encrypt' || $stored === '') {
            return null;
        }

        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return null;
        }

        try {
            $decrypted = Craft::$app->getSecurity()->decryptByKey($raw);
        } catch (\Throwable) {
            return null;
        }

        return $decrypted === false ? null : $decrypted;
    }

    // --- Per-entry secrets ({{%sesame_entry_secrets}}), keyed by element uid ---

    /** @return array{secret:string,mode:string,epoch:int}|null */
    public function getForEntry(string $elementUid): ?array
    {
        $row = (new Query())
            ->select(['secret', 'secretMode', 'epoch'])
            ->from(Install::ENTRY_SECRETS_TABLE)
            ->where(['elementUid' => $elementUid])
            ->one();

        return $row ? [
            'secret' => (string) $row['secret'],
            'mode' => (string) $row['secretMode'],
            'epoch' => (int) ($row['epoch'] ?? 0),
        ] : null;
    }

    public function hasEntrySecret(string $elementUid): bool
    {
        $row = $this->getForEntry($elementUid);
        // A 'disabled' tombstone ({@see disableForEntry()}) carries the
        // revocation epoch but no usable secret — treat it as "no secret" so a
        // re-enable without a fresh password fails closed, exactly as it did
        // when disable hard-deleted the row.
        return $row !== null && $row['mode'] !== 'disabled';
    }

    /** Encodes (per `settings.hashPasswords`) and upserts the password for one entry. */
    public function storeForEntry(string $elementUid, string $rawPassword): void
    {
        $encoded = $this->store($rawPassword);
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $existingId = (new Query())
            ->select(['id'])
            ->from(Install::ENTRY_SECRETS_TABLE)
            ->where(['elementUid' => $elementUid])
            ->scalar();

        if ($existingId) {
            // Replacing an existing per-entry password is a credential change:
            // bump the epoch atomically so anyone holding an old unlock is cut
            // off (same contract as Rules::save() with $bumpEpoch). A brand-new
            // row starts at epoch 0 — nobody holds an unlock for it yet.
            $db->createCommand()->update(Install::ENTRY_SECRETS_TABLE, [
                'secret' => $encoded['secret'],
                'secretMode' => $encoded['mode'],
                'epoch' => new Expression('[[epoch]] + 1'),
                'dateUpdated' => $now,
            ], ['id' => $existingId])->execute();
        } else {
            $db->createCommand()->insert(Install::ENTRY_SECRETS_TABLE, [
                'elementUid' => $elementUid,
                'secret' => $encoded['secret'],
                'secretMode' => $encoded['mode'],
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Hard-deletes the per-entry secret row. Used only when the entry ITSELF is
     * deleted ({@see \iceboxind\sesame\fields\Protect::afterElementDelete()}) —
     * turning protection off without deleting the entry goes through
     * {@see disableForEntry()} instead, which preserves the revocation epoch.
     */
    public function clearForEntry(string $elementUid): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Install::ENTRY_SECRETS_TABLE, ['elementUid' => $elementUid])
            ->execute();
    }

    /**
     * Protection was switched OFF for an entry that still exists. We do NOT
     * hard-delete the row: the session key is the entry's (stable) element uid,
     * so a hard delete would reset the epoch to 0 on a later re-enable and let
     * an in-session unlock survive a password change made across a
     * disable→re-enable cycle. Instead keep the row as a tombstone — blank the
     * secret (so the old password is gone and a re-enable must set a new one),
     * mark it 'disabled' so {@see \iceboxind\sesame\services\Gate::isProtected()}
     * treats the entry as unprotected, and bump the epoch so the counter stays
     * monotonic. No-op when the entry was never protected (nothing to revoke).
     */
    public function disableForEntry(string $elementUid): void
    {
        $existingId = (new Query())
            ->select(['id'])
            ->from(Install::ENTRY_SECRETS_TABLE)
            ->where(['elementUid' => $elementUid])
            ->scalar();

        if (!$existingId) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(Install::ENTRY_SECRETS_TABLE, [
            'secret' => '',
            'secretMode' => 'disabled',
            'epoch' => new Expression('[[epoch]] + 1'),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['id' => $existingId])->execute();
    }

    /**
     * Records that an entry is protected but has NO usable password yet (the
     * editor turned the lightswitch on without setting one). Stored as an
     * empty secret so {@see verify()} rejects every input — the gate challenges
     * and nothing unlocks (fail CLOSED), instead of the entry falling through
     * unprotected. The editor is nudged to set a real password by the field UI.
     */
    public function markProtectedWithoutSecret(string $elementUid): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $existingId = (new Query())
            ->select(['id'])
            ->from(Install::ENTRY_SECRETS_TABLE)
            ->where(['elementUid' => $elementUid])
            ->scalar();

        if ($existingId) {
            // Clearing a real password (protection kept on, password removed) is
            // a revocation: bump the epoch so anyone who unlocked with the old
            // password is cut off, not left holding a live session against a
            // now-empty (fail-closed) secret.
            $db->createCommand()->update(Install::ENTRY_SECRETS_TABLE, [
                'secret' => '',
                'secretMode' => 'encrypt',
                'epoch' => new Expression('[[epoch]] + 1'),
                'dateUpdated' => $now,
            ], ['id' => $existingId])->execute();
        } else {
            $db->createCommand()->insert(Install::ENTRY_SECRETS_TABLE, [
                'elementUid' => $elementUid,
                'secret' => '',
                'secretMode' => 'encrypt',
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }
}
