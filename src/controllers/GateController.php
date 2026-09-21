<?php

namespace dgaidula\sesame\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use dgaidula\sesame\models\Scope;
use dgaidula\sesame\Plugin;
use nystudio107\seomatic\Seomatic;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end gate. Reached two ways:
 *  - actionChallenge: routed to directly by {@see \dgaidula\sesame\Plugin::init()}'s
 *    EVENT_SET_ROUTE handler, in place of the entry's normal template render.
 *  - actionUnlock: posted to from the challenge screen itself.
 *
 * CSRF stays ON (default) — this is a normal browser form POST, not an API
 * (PATTERNS.md §3).
 */
class GateController extends Controller
{
    protected array|int|bool $allowAnonymous = ['challenge', 'unlock', 'link'];

    public function actionChallenge(string $t, string $return = ''): Response
    {
        $scope = $this->resolveScope($t);
        if ($scope === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'This page is not available.'));
        }

        // Constrain the redirect target to this site BEFORE it is either
        // followed or hashed into the form (CWE-601 open redirect).
        $return = $this->safeReturn($return, Craft::$app->getRequest()->getPathInfo());

        // Already unlocked this session (bookmark, back button) — nothing to
        // challenge, send them straight through.
        if (Plugin::getInstance()->gate->isUnlocked($scope)) {
            return $this->redirect($return);
        }

        $this->disableSeomaticRender();

        return $this->renderChallenge($scope, $t, $return);
    }

    /**
     * PRO. Reached by clicking a magic link minted from the Rules edit
     * screen ({@see \dgaidula\sesame\controllers\RulesController::actionMintLink()}).
     * Validates the token itself (signature + expiry), then re-resolves the
     * scope LIVE from the current rule set — an edited password, a disabled
     * rule, or a deleted rule all take effect immediately, exactly like the
     * regular challenge/unlock flow (the token never carries the secret).
     */
    public function actionLink(string $t): Response
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new NotFoundHttpException(Craft::t('sesame', 'This page is not available.'));
        }

        $gate = Plugin::getInstance()->gate;
        $data = $gate->readMagicLink($t);
        if ($data === null || $data['type'] !== 'rule') {
            throw new NotFoundHttpException(Craft::t('sesame', 'This link has expired or is no longer valid.'));
        }

        $scope = $gate->scopeFromToken($data);
        if ($scope === null) {
            // Rule was deleted or disabled since the link was minted.
            throw new NotFoundHttpException(Craft::t('sesame', 'This link has expired or is no longer valid.'));
        }

        $gate->unlock($scope);
        Plugin::getInstance()->accessLog->record('unlock', $scope, Craft::$app->getRequest());

        return $this->redirect($this->safeReturn($data['target'], '/'));
    }

    public function actionUnlock(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $t = (string) $request->getRequiredBodyParam('t');
        // Constrain to this site before it is re-hashed into the form or used
        // as the redirect fallback (CWE-601 open redirect).
        $return = $this->safeReturn((string) $request->getBodyParam('return', ''), '/');
        $password = (string) $request->getBodyParam('password', '');

        $scope = $this->resolveScope($t);
        if ($scope === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'This page is not available.'));
        }

        $gate = Plugin::getInstance()->gate;
        $throttle = Plugin::getInstance()->throttle;
        $ip = $request->getUserIP() ?: '0.0.0.0';
        $scopeKey = $gate->scopeKey($scope);

        // Checked BEFORE Secrets::verify so a flood also can't spend bcrypt CPU.
        if ($throttle->tooMany($ip, $scopeKey)) {
            Plugin::getInstance()->accessLog->record('throttle', $scope, $request);
            return $this->renderChallenge($scope, $t, $return, null, true);
        }

        // TODO Pro: reCAPTCHA v3 verification goes here (settings.recaptchaSecret
        // / recaptchaSiteKey, an obfuscated field name per guardrail 7 in the
        // consuming site's CLAUDE.md / PATTERNS.md §9), before the password
        // compare — so a scored-bad submission never even reaches Secrets::verify.
        // Not built; Lite/Pro both skip straight to the throttle+password checks.

        if (!Plugin::getInstance()->secrets->verify($password, $scope->secret, $scope->secretMode)) {
            $throttle->record($ip, $scopeKey);
            Plugin::getInstance()->accessLog->record('fail', $scope, $request);
            return $this->renderChallenge($scope, $t, $return, Craft::t('sesame', 'Incorrect password.'));
        }

        // PRO. Only meaningful when the scope's rule opted in AND a duration
        // is configured — Gate::unlock() itself no-ops the cookie otherwise
        // (and always on Lite). The checkbox that produces this is only
        // ever rendered under those same conditions (see renderChallenge()).
        $remember = (bool) $request->getBodyParam('remember', false);
        $gate->unlock($scope, $remember);
        Plugin::getInstance()->accessLog->record('unlock', $scope, $request);

        // `redirect` is the hashed field from the template's redirectInput();
        // $return is only the un-trusted display default (never trusted on
        // its own — Craft validates the hash before honoring it).
        return $this->redirectToPostedUrl(null, $return ?: $request->getPathInfo());
    }

    private function resolveScope(string $token): ?Scope
    {
        $data = Plugin::getInstance()->gate->readScopeToken($token);
        return $data === null ? null : Plugin::getInstance()->gate->scopeFromToken($data);
    }

    /**
     * Constrains a redirect target to this site so the `return`/`target` value
     * can't be turned into an open redirect (CWE-601) — including via the
     * template's `redirectInput()`, which hashes whatever it is given and so
     * would otherwise let an attacker-supplied URL through the hash check.
     *
     * Accepts a site-relative path (single leading slash, not protocol-relative)
     * or a full URL on the current site's own host; anything else (a full
     * cross-origin URL, `//evil.com`, `/\evil.com`) falls back to $fallback.
     */
    private function safeReturn(string $return, string $fallback): string
    {
        $return = trim($return);
        if ($return === '') {
            return $fallback;
        }

        if (str_starts_with($return, '/') && !str_starts_with($return, '//') && !str_starts_with($return, '/\\')) {
            return $return;
        }

        if (UrlHelper::isFullUrl($return)) {
            $siteHost = parse_url((string) UrlHelper::baseSiteUrl(), PHP_URL_HOST);
            $returnHost = parse_url($return, PHP_URL_HOST);
            if (is_string($siteHost) && is_string($returnHost) && strcasecmp($siteHost, $returnHost) === 0) {
                return $return;
            }
        }

        return $fallback;
    }

    private function renderChallenge(
        Scope $scope,
        string $token,
        string $return,
        ?string $error = null,
        bool $cooldown = false,
    ): Response {
        // Best-effort front-end hygiene for a page that must exist behind a
        // password — see PATTERNS.md §8 for what this does and doesn't cover.
        Craft::$app->getResponse()->getHeaders()->set('X-Robots-Tag', 'none');

        $template = $scope->templateOverride ?: 'sesame/gate/challenge';

        return $this->renderTemplate($template, [
            'token' => $token,
            'return' => $return,
            'message' => $scope->message ?: Craft::t('sesame', 'This page is protected. Enter the password to continue.'),
            'error' => $error,
            'cooldown' => $cooldown,
            // PRO. The checkbox only ever appears when it could actually do
            // something — Lite visitors never see it.
            'showRememberMe' => Plugin::getInstance()->isPro() && $scope->rememberMe,
        ]);
    }

    /**
     * SEOmatic listens for `View::EVENT_END_PAGE` and injects the
     * *originally-matched* entry's `<title>`/meta/JSON-LD into whatever HTML
     * happens to render for the request — including this challenge screen —
     * because it resolves its metadata from the route's element, not from
     * what actually got rendered. That leaks a protected page's title (and
     * breadcrumb structured data) to anyone who hits the challenge, defeating
     * the point of gating it. Disabling its render for this one request
     * closes that. Lite feature (guardrail: never fatal if SEOmatic isn't
     * installed, isn't enabled, or its internals have moved).
     *
     * Verified against `vendor/nystudio107/craft-seomatic` as installed in
     * this project (2026-09-16):
     *   - `src/Seomatic.php:128` — `public static ?Settings $settings = null;`
     *   - `src/Seomatic.php:761` (inside the `View::EVENT_END_PAGE` handler
     *     registered in `handleSiteRequest()`) —
     *     `if (self::$settings->renderEnabled && self::$seomaticVariable) { self::$plugin->metaContainers->includeMetaContainers(); }`
     *     — the actual injection gate this flips off.
     */
    private function disableSeomaticRender(): void
    {
        if (!class_exists(Seomatic::class)) {
            return;
        }

        try {
            if (Seomatic::$settings !== null) {
                Seomatic::$settings->renderEnabled = false;
            }
        } catch (\Throwable $e) {
            // Never fatal the challenge screen over a third-party plugin's
            // internals shifting under us.
        }
    }
}
