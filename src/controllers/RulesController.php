<?php

namespace iceboxind\sesame\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use iceboxind\sesame\models\Rule;
use iceboxind\sesame\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP screen for the rule set — the "no template code" way to protect a page
 * (a URI glob, or a section/entry-type handle) behind a shared password.
 * This is the plugin's main CP section; the per-entry Protect field is
 * configured on the entry itself (`fields/Protect` + `_field/protect-input.twig`),
 * not here.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_RULES);
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('sesame/rules/index', [
            'rules' => Plugin::getInstance()->rules->all(),
        ]);
    }

    public function actionEdit(?string $uid = null): Response
    {
        $rule = null;
        if ($uid !== null) {
            $rule = Plugin::getInstance()->rules->getByUid($uid);
            if ($rule === null) {
                throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
            }
        }

        return $this->renderEdit($rule ?? new Rule());
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $uid = $request->getBodyParam('uid') ?: null;
        $isNew = $uid === null;
        $rule = $isNew ? new Rule() : Plugin::getInstance()->rules->getByUid((string) $uid);
        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
        }

        $rule->label = (string) $request->getBodyParam('label', $rule->label);
        $rule->matchType = (string) $request->getBodyParam('matchType', $rule->matchType);
        $rule->pattern = (string) $request->getBodyParam('pattern', $rule->pattern);
        $rule->message = $request->getBodyParam('message') ?: null;
        $rule->templateOverride = $request->getBodyParam('templateOverride') ?: null;
        $rule->enabled = (bool) $request->getBodyParam('enabled', false);
        // PRO. Lite ignores whatever posts here — forced false so a Lite
        // install can never end up with rememberMe=true on a saved rule
        // (the field is only rendered on the edit screen when isPro).
        $rule->rememberMe = Plugin::getInstance()->isPro() && (bool) $request->getBodyParam('rememberMe', false);

        // A blank password on an EXISTING rule keeps the stored secret
        // unchanged — $rule was hydrated from the saved row above, so
        // secret/secretMode are already correct unless we overwrite them below.
        $password = (string) $request->getBodyParam('password', '');
        if ($password !== '') {
            $encoded = Plugin::getInstance()->secrets->store($password);
            $rule->secret = $encoded['secret'];
            $rule->secretMode = $encoded['mode'];
        } elseif ($isNew) {
            $rule->addError('secret', Craft::t('sesame', 'A password is required.'));
        }

        if ($rule->hasErrors() || !Plugin::getInstance()->rules->save($rule)) {
            Craft::$app->getSession()->setError(Craft::t('sesame', 'Couldn’t save the rule.'));
            return $this->renderEdit($rule);
        }

        Craft::$app->getSession()->setNotice(Craft::t('sesame', 'Rule saved.'));
        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');

        Plugin::getInstance()->rules->delete($uid);
        Craft::$app->getSession()->setNotice(Craft::t('sesame', 'Rule deleted.'));

        return $this->redirectToPostedUrl();
    }

    /** Moves one rule up or down one place, swapping it with its neighbor. */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $uid = (string) $request->getRequiredBodyParam('uid');
        $direction = (string) $request->getBodyParam('direction', 'down');

        $uids = array_keys(Plugin::getInstance()->rules->all());
        $index = array_search($uid, $uids, true);
        if ($index !== false) {
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if (isset($uids[$swapWith])) {
                [$uids[$index], $uids[$swapWith]] = [$uids[$swapWith], $uids[$index]];
                Plugin::getInstance()->rules->reorder($uids);
            }
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * PRO. Mints a shareable magic-link URL for one rule ("Copy shareable
     * link" on the Rules edit screen) — POST-only, JSON response, gated by
     * the same `sesame:manageRules` permission as every other action here
     * (`beforeAction()`), plus an explicit `isPro()` check since this is a
     * Pro-only capability that a Lite install must refuse even if someone
     * guesses the action route directly.
     */
    public function actionMintLink(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->isPro()) {
            return $this->asJson(['error' => Craft::t('sesame', 'Shareable links are a Sesame Pro feature.')]);
        }

        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');
        $rule = Plugin::getInstance()->rules->getByUid($uid);
        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
        }

        // Default 7 days, editor-adjustable per mint via `ttlDays`.
        $ttlDays = max(1, (int) Craft::$app->getRequest()->getBodyParam('ttlDays', 7));
        $ttl = $ttlDays * 86400;

        // A URI rule with no glob has one unambiguous target page — link
        // straight to it. A globbed URI, or a section/entry-type rule,
        // protects many pages at once with no single "the" page, so the
        // link just lands on the unlock and sends the visitor to the site
        // root; they're unlocked for everything the rule covers either way.
        $target = ($rule->matchType === 'uri' && !str_contains($rule->pattern, '*')) ? $rule->pattern : '';

        $token = Plugin::getInstance()->gate->signMagicLink($rule->toScope(), $ttl, $target);

        return $this->asJson([
            'url' => UrlHelper::siteUrl('sesame/gate/link', ['t' => $token]),
            'ttlDays' => $ttlDays,
        ]);
    }

    /**
     * Returns the current password for CP display — decrypted, encrypt-mode
     * only. Hash-mode rules are write-only, so this returns null and the
     * edit screen shows "written-only" instead of a value.
     */
    public function actionRevealPassword(): Response
    {
        $this->requirePostRequest();
        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');

        $rule = Plugin::getInstance()->rules->getByUid($uid);
        $password = $rule ? Plugin::getInstance()->secrets->reveal($rule->secret, $rule->secretMode) : null;

        return $this->asJson(['password' => $password]);
    }

    private function renderEdit(Rule $rule): Response
    {
        $entries = Craft::$app->getEntries();

        $sectionOptions = array_map(
            static fn($section) => ['label' => $section->name, 'value' => $section->handle],
            $entries->getAllSections()
        );
        $entryTypeOptions = array_map(
            static fn($entryType) => ['label' => $entryType->name, 'value' => $entryType->handle],
            $entries->getAllEntryTypes()
        );

        return $this->renderTemplate('sesame/rules/_edit', [
            'rule' => $rule,
            'isNew' => $rule->uid === null,
            'sectionOptions' => $sectionOptions,
            'entryTypeOptions' => $entryTypeOptions,
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }
}
