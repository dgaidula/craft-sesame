<?php

namespace iceboxind\sesame\controllers;

use Craft;
use craft\web\Controller;
use iceboxind\sesame\services\Branding;
use iceboxind\sesame\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * PRO. CP screen for the site-default challenge-screen branding (P1.3) —
 * `Sesame → Branding`. Backed by the single-row {{%sesame_branding}} table, so
 * it is editable on production regardless of `allowAdminChanges` (like Rules,
 * unlike project-config settings). Per-rule overrides live on the rule's own
 * edit screen. Lite hides the nav item and this controller refuses directly.
 */
class BrandingController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_RULES);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('sesame', 'Branding is a Sesame Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $defaults = Plugin::getInstance()->branding->siteDefaults();
        $logo = $defaults['logoId'] ? Craft::$app->getAssets()->getAssetById($defaults['logoId']) : null;

        return $this->renderTemplate('sesame/branding/index', [
            'defaults' => $defaults,
            'logo' => $logo,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $logoIds = $request->getBodyParam('logoId');
        $logoId = is_array($logoIds) ? ((int) ($logoIds[0] ?? 0) ?: null) : null;

        Plugin::getInstance()->branding->saveSiteDefaults(
            $logoId,
            trim((string) $request->getBodyParam('heading', '')) ?: null,
            trim((string) $request->getBodyParam('intro', '')) ?: null,
            Branding::sanitizeAccent($request->getBodyParam('accent')),
        );

        Craft::$app->getSession()->setNotice(Craft::t('sesame', 'Branding saved.'));
        return $this->redirectToPostedUrl();
    }
}
