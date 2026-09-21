<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\models\Scope;
use iceboxind\sesame\Plugin;

/**
 * PRO. The unlock/fail/throttle audit trail — {{%sesame_access_log}}.
 *
 * Lite never writes here: every write goes through {@see record()}, which
 * no-ops unless `Plugin::isPro()`. The table itself is created by the
 * install migration regardless of edition (schema present, Lite never reads
 * or writes it) so upgrading to Pro doesn't need a fresh migration.
 */
class AccessLog extends Component
{
    /** @var 'unlock'|'fail'|'throttle' $event */
    public function record(string $event, ?Scope $scope, Request $request): void
    {
        if (!Plugin::getInstance()->isPro() || $scope === null) {
            return;
        }

        $ruleId = null;
        $elementId = null;

        if ($scope->type === 'rule') {
            $ruleId = Plugin::getInstance()->rules->getByUid($scope->uid)?->id;
        } elseif ($scope->type === 'entry') {
            $elementId = Craft::$app->getElements()->getElementByUid($scope->uid)?->id;
        }

        Craft::$app->getDb()->createCommand()->insert(Install::ACCESS_LOG_TABLE, [
            'ruleId' => $ruleId,
            'elementId' => $elementId,
            'scopeKey' => Plugin::getInstance()->gate->scopeKey($scope),
            'event' => $event,
            'ip' => $request->getUserIP() ?: '0.0.0.0',
            'userAgent' => $request->getUserAgent() ?: '',
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /** @return array[] Most recent events first, raw rows (CP index enriches them with rule/entry labels). */
    public function recent(int $limit = 100): array
    {
        return (new Query())
            ->from(Install::ACCESS_LOG_TABLE)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Hard-deletes log rows older than the retention window. Runs on
     * `Gc::EVENT_RUN` (mirrors Downtoll's `Submissions::purgeExpired()`
     * pattern) — a no-op on Lite (the caller guards with `isPro()`) and a
     * no-op when retention is disabled (0 or less).
     */
    public function purgeExpired(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->accessLogRetentionDays;
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTime())->sub(new \DateInterval("P{$days}D"));

        return (int) Craft::$app->getDb()->createCommand()
            ->delete(Install::ACCESS_LOG_TABLE, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }
}
