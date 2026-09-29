<?php

namespace iceboxind\sesame\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;
use iceboxind\sesame\services\StaticCache;

/**
 * 1.0.1: section and entry-type rules store the target's UID, not its handle.
 *
 * In 1.0.0 a section / entry-type rule kept the handle in `pattern`, so renaming
 * that handle in the CP made the rule stop matching and its pages went public.
 * This converts every such row to the target's UID. Idempotent: a row that
 * already holds a UID is skipped. A handle that no longer resolves (its section
 * or entry type was already renamed or deleted) is left as-is and logged — it
 * matched nothing before and still matches nothing; the Rules screen flags it.
 */
class m260928_000000_section_rules_by_uid extends Migration
{
    public function safeUp(): bool
    {
        $entries = Craft::$app->getEntries();
        $rows = (new Query())
            ->select(['id', 'matchType', 'pattern'])
            ->from(Install::RULES_TABLE)
            ->where(['matchType' => ['section', 'entryType']])
            ->all($this->db);

        foreach ($rows as $row) {
            if (StringHelper::isUUID((string) $row['pattern'])) {
                continue;
            }

            $uid = $row['matchType'] === 'section'
                ? $entries->getSectionByHandle((string) $row['pattern'])?->uid
                : ($entries->getEntryTypeByHandle((string) $row['pattern'])?->uid ?? $this->overriddenTypeUid((string) $row['pattern']));

            if ($uid === null) {
                Craft::warning("Sesame rule {$row['id']}: {$row['matchType']} “{$row['pattern']}” no longer exists; left unchanged (it protects nothing).", __METHOD__);
                continue;
            }

            $this->update(Install::RULES_TABLE, ['pattern' => $uid], ['id' => $row['id']]);
            $converted = true;
        }

        // An entry-type rule now also covers entries whose section overrides the
        // type's handle (1.0.0 missed them), so pages may have just become
        // protected — drop any static copies of them.
        if (!empty($converted)) {
            (new StaticCache())->purgeAll();
        }

        return true;
    }

    /**
     * 1.0.0 matched `$entry->getType()->handle`, which returns a section's
     * per-section handle OVERRIDE for that entry type when one is set — so a
     * 1.0.0 entry-type rule could hold an override handle that
     * getEntryTypeByHandle() (native handles only) can't resolve. Map it when
     * exactly one entry type uses that override; ambiguity stays unresolved.
     */
    private function overriddenTypeUid(string $handle): ?string
    {
        $uids = (new Query())
            ->select(['et.uid'])
            ->distinct()
            ->from(['se' => '{{%sections_entrytypes}}'])
            ->innerJoin(['et' => '{{%entrytypes}}'], '[[et.id]] = [[se.typeId]]')
            ->where(['se.handle' => $handle])
            ->column($this->db);

        return count($uids) === 1 ? $uids[0] : null;
    }

    public function safeDown(): bool
    {
        echo "m260928_000000_section_rules_by_uid cannot be reverted.\n";
        return false;
    }
}
