<?php

namespace iceboxind\sesame\controllers;

use Craft;
use craft\web\Controller;
use iceboxind\sesame\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * PRO. CP index for {{%sesame_access_log}} — the audit trail AccessLog::record()
 * writes at the unlock/fail/throttle seams in {@see GateController}. Lite
 * hides the nav item entirely (`Plugin::getCpNavItem()`); this controller
 * also refuses directly on a Lite install, in case someone hits the URL by
 * hand — the same belt-and-suspenders pattern as RulesController::actionMintLink().
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_LOG);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('sesame', 'The access log is a Sesame Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $rulesById = [];
        foreach (Plugin::getInstance()->rules->all() as $rule) {
            if ($rule->id) {
                $rulesById[$rule->id] = $rule->label;
            }
        }

        $elements = Craft::$app->getElements();
        $events = array_map(function (array $row) use ($rulesById, $elements): array {
            if (!empty($row['ruleId']) && isset($rulesById[(int) $row['ruleId']])) {
                $row['target'] = $rulesById[(int) $row['ruleId']];
            } elseif (!empty($row['elementId'])) {
                $element = $elements->getElementById((int) $row['elementId']);
                $row['target'] = $element->title ?? Craft::t('sesame', 'Entry #{id}', ['id' => $row['elementId']]);
            } else {
                $row['target'] = $row['scopeKey'];
            }

            return $row;
        }, Plugin::getInstance()->accessLog->recent(100));

        return $this->renderTemplate('sesame/log/index', [
            'events' => $events,
        ]);
    }
}
