<?php

namespace iceboxind\sesame\migrations;

use craft\db\Migration;

/**
 * Install migration.
 *
 * Creates the three plugin-owned tables. All content-layer (plain DB tables
 * written by the plugin's own services/controllers), NOT project config — so
 * rules and per-entry secrets are editable on production even when
 * `allowAdminChanges` is false. Idempotent (tableExists guards) so it is safe
 * to run against a partially-installed environment.
 */
class Install extends Migration
{
    /** Multi-row rule set: URI/section/entry-type matches → a stored secret. */
    public const RULES_TABLE = '{{%sesame_rules}}';

    /** PRO: per-unlock audit trail. Schema created now; Lite never writes to it. */
    public const ACCESS_LOG_TABLE = '{{%sesame_access_log}}';

    /** Per-entry (Protect field) secrets, keyed by the owning element's UID. */
    public const ENTRY_SECRETS_TABLE = '{{%sesame_entry_secrets}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::RULES_TABLE)) {
            $this->createTable(self::RULES_TABLE, [
                'id' => $this->primaryKey(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->smallInteger()->notNull()->defaultValue(0),
                'label' => $this->string()->notNull(),
                'matchType' => $this->string(20)->notNull(), // uri | section | entryType
                'pattern' => $this->string()->notNull(),
                'secret' => $this->text(), // stored (encrypted or bcrypt), NEVER plaintext
                'secretMode' => $this->string(10)->notNull()->defaultValue('encrypt'), // encrypt | hash
                'message' => $this->text(),
                'templateOverride' => $this->string(),
                // Revocation epoch (both editions): bumped whenever this rule's
                // password changes or an admin revokes access. A session /
                // remember-me cookie / magic link carries the epoch it was
                // minted under; the gate compares it against this live value, so
                // a password change instantly cuts off everyone holding an old
                // one. See services/Gate.php.
                'epoch' => $this->integer()->notNull()->defaultValue(0),
                // Pro columns — present in schema, Lite never reads/writes them.
                'codesJson' => $this->text(),
                // Scheduled lock/unlock (P1.1): the rule protects only within the
                // window [protectFrom, protectUntil). Either bound is nullable —
                // null protectFrom = active from the start, null protectUntil =
                // never expires, both null = always active. Stored UTC.
                'protectFrom' => $this->dateTime(),
                'protectUntil' => $this->dateTime(),
                'rememberMe' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::RULES_TABLE, ['sortOrder']);
        }

        if (!$this->db->tableExists(self::ACCESS_LOG_TABLE)) {
            $this->createTable(self::ACCESS_LOG_TABLE, [
                'id' => $this->primaryKey(),
                'ruleId' => $this->integer(),
                'elementId' => $this->integer(),
                // The acting CP / logged-in user, when there is one — null for an
                // anonymous front-end unlock/fail/throttle, set for a reveal (and
                // any unlock by a logged-in user). "Who" is the key audit field.
                'userId' => $this->integer(),
                'scopeKey' => $this->string()->notNull(),
                'event' => $this->string(20)->notNull(), // unlock | link | fail | throttle | reveal
                'ip' => $this->string(45)->notNull(), // long enough for IPv6
                'userAgent' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::ACCESS_LOG_TABLE, ['scopeKey']);
            $this->addForeignKey(null, self::ACCESS_LOG_TABLE, ['ruleId'], self::RULES_TABLE, ['id'], 'SET NULL', null);
            $this->addForeignKey(null, self::ACCESS_LOG_TABLE, ['elementId'], '{{%elements}}', ['id'], 'CASCADE', null);
            $this->addForeignKey(null, self::ACCESS_LOG_TABLE, ['userId'], '{{%users}}', ['id'], 'SET NULL', null);
        }

        if (!$this->db->tableExists(self::ENTRY_SECRETS_TABLE)) {
            $this->createTable(self::ENTRY_SECRETS_TABLE, [
                'id' => $this->primaryKey(),
                'elementUid' => $this->uid()->notNull(),
                'secret' => $this->text()->notNull(),
                'secretMode' => $this->string(10)->notNull()->defaultValue('encrypt'), // encrypt | hash
                // Revocation epoch (both editions) — same contract as the rules
                // table above: bumped on password change / clear, compared live
                // by the gate so a per-entry password change locks people out.
                'epoch' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::ENTRY_SECRETS_TABLE, ['elementUid'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Drop the FK-holding table first.
        $this->dropTableIfExists(self::ACCESS_LOG_TABLE);
        $this->dropTableIfExists(self::ENTRY_SECRETS_TABLE);
        $this->dropTableIfExists(self::RULES_TABLE);
        return true;
    }
}
