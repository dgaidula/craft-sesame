<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\Plugin;

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

        return ['secret' => $security->encryptByKey($raw), 'mode' => 'encrypt'];
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

        $decrypted = Craft::$app->getSecurity()->decryptByKey($stored);
        return $decrypted === false ? null : $decrypted;
    }

    // --- Per-entry secrets ({{%sesame_entry_secrets}}), keyed by element uid ---

    /** @return array{secret:string,mode:string}|null */
    public function getForEntry(string $elementUid): ?array
    {
        $row = (new Query())
            ->select(['secret', 'secretMode'])
            ->from(Install::ENTRY_SECRETS_TABLE)
            ->where(['elementUid' => $elementUid])
            ->one();

        return $row ? ['secret' => (string) $row['secret'], 'mode' => (string) $row['secretMode']] : null;
    }

    public function hasEntrySecret(string $elementUid): bool
    {
        return $this->getForEntry($elementUid) !== null;
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
            $db->createCommand()->update(Install::ENTRY_SECRETS_TABLE, [
                'secret' => $encoded['secret'],
                'secretMode' => $encoded['mode'],
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

    public function clearForEntry(string $elementUid): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Install::ENTRY_SECRETS_TABLE, ['elementUid' => $elementUid])
            ->execute();
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
            $db->createCommand()->update(Install::ENTRY_SECRETS_TABLE, [
                'secret' => '',
                'secretMode' => 'encrypt',
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
