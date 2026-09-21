<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\Json;
use iceboxind\sesame\models\Scope;
use iceboxind\sesame\Plugin;
use yii\web\Cookie;

/**
 * The gate: decides whether an entry is protected, whether the current
 * session has already unlocked it, and issues/validates the signed scope
 * token that ties a challenge-screen POST back to a specific rule or
 * per-entry secret without ever putting the secret itself on the wire
 * (PATTERNS.md §3).
 */
class Gate extends Component
{
    private const SESSION_PREFIX = 'sesame.unlocked.';

    /** PRO. Remember-me cookie name prefix (§ below) — sha1'd scope key appended, never the scope key itself. */
    private const REMEMBER_COOKIE_PREFIX = 'sesame_remember_';

    /**
     * Per-entry protection (the Protect field) is the more specific,
     * editor-facing override, so it's checked first; falls back to the rule
     * set otherwise.
     */
    public function isProtected(Entry $entry): ?Scope
    {
        if ($entry->uid) {
            $entryScope = $this->entryScope($entry->uid);
            if ($entryScope !== null) {
                return $entryScope;
            }
        }

        return Plugin::getInstance()->rules->match($entry);
    }

    public function scopeKey(Scope $scope): string
    {
        return $scope->scopeKey ??= "{$scope->type}.{$scope->uid}";
    }

    public function isUnlocked(Scope $scope): bool
    {
        // The session value is a shape ({@see unlock()}), not a bare `true`, so
        // it can carry the revocation epoch (and, later, a Pro code id). A
        // stored epoch that no longer matches the live one — because the
        // password changed or access was revoked — is treated as not unlocked,
        // and any legacy bare `true` (never an array) fails closed too.
        $stored = Craft::$app->getSession()->get($this->sessionKey($scope), null);
        if (is_array($stored) && (int) ($stored['e'] ?? -1) === $scope->epoch) {
            return true;
        }

        // PRO. A session unlock alone doesn't survive session expiry/browser
        // restart; a valid remember-me cookie does. No-op on Lite.
        return Plugin::getInstance()->isPro() && $this->hasValidRememberCookie($scope);
    }

    /**
     * @param bool $remember PRO. When true (and the scope's rule opted in via
     * `rememberMe`, and `settings.rememberMeDuration` > 0, and the edition is
     * Pro), also sets a persistent signed cookie so the unlock survives
     * session expiry. Ignored entirely on Lite.
     */
    public function unlock(Scope $scope, bool $remember = false): void
    {
        // Store the epoch this unlock was granted under, not a bare `true`, so
        // {@see isUnlocked()} can revoke it when the epoch is later bumped. An
        // array (rather than the int alone) keeps room for a Pro code id (`c`)
        // without another session-shape change later.
        Craft::$app->getSession()->set($this->sessionKey($scope), ['e' => $scope->epoch]);

        if ($remember && $scope->rememberMe && Plugin::getInstance()->isPro()) {
            $duration = Plugin::getInstance()->getSettings()->rememberMeDuration;
            if ($duration > 0) {
                $this->setRememberCookie($scope, $duration);
            }
        }
    }

    // --- PRO: remember-me cookie ---
    //
    // Signed-cookie-with-exp (`hashData(json{k:scopeKey,exp})`), not an
    // opaque token mapped server-side — deliberately simpler: no extra table
    // to write, read, or garbage-collect for a feature that is already
    // read-mostly. The tradeoff: it isn't individually revocable — the only
    // way to invalidate every outstanding remember-me cookie at once is
    // rotating the app security key (`securityKey` in general config), which
    // also invalidates every other hashData()-signed token in the app
    // (scope tokens, magic links). Acceptable here; called out so it's a
    // deliberate choice, not an oversight (see SESAME-PRO-BRIEF.md §C).

    private function setRememberCookie(Scope $scope, int $duration): void
    {
        $value = Craft::$app->getSecurity()->hashData(Json::encode([
            'k' => $this->scopeKey($scope),
            'ep' => $scope->epoch,
            'exp' => time() + $duration,
        ]));

        Craft::$app->getResponse()->getCookies()->add(new Cookie([
            'name' => $this->rememberCookieName($scope),
            'value' => $value,
            'expire' => time() + $duration,
            'httpOnly' => true,
            'secure' => true,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));
    }

    private function hasValidRememberCookie(Scope $scope): bool
    {
        $cookie = Craft::$app->getRequest()->getCookies()->get($this->rememberCookieName($scope));
        if ($cookie === null || !is_string($cookie->value) || $cookie->value === '') {
            return false;
        }

        $json = Craft::$app->getSecurity()->validateData($cookie->value);
        if ($json === false) {
            return false;
        }

        $data = Json::decode($json);
        if (empty($data['k']) || empty($data['exp']) || (int) $data['exp'] < time()) {
            return false;
        }

        // A cookie minted under a superseded epoch is revoked, exactly like a
        // stale session value.
        if ((int) ($data['ep'] ?? -1) !== $scope->epoch) {
            return false;
        }

        return hash_equals($this->scopeKey($scope), (string) $data['k']);
    }

    /** Keyed by a hash of the scope key (never the scope key itself, in the cookie name). */
    private function rememberCookieName(Scope $scope): string
    {
        return self::REMEMBER_COOKIE_PREFIX . sha1($this->scopeKey($scope));
    }

    // --- PRO: shareable magic links ---

    /** Signs a time-limited link token: scope type+uid (re-resolved live on click, never trusted from the token) + the epoch it was minted under + an optional redirect target + expiry. */
    public function signMagicLink(Scope $scope, int $ttl, string $target = ''): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode([
            'type' => $scope->type,
            'uid' => $scope->uid,
            'ep' => $scope->epoch,
            'target' => $target,
            'exp' => time() + $ttl,
        ]));
    }

    /**
     * Validates + decodes a {@see signMagicLink()} token. Checks signature
     * and expiry only — the caller (`GateController::actionLink`) is still
     * responsible for re-resolving the scope live and confirming it still
     * matches an enabled rule (and that the epoch still matches) before
     * unlocking anything.
     *
     * @return array{type:string,uid:string,epoch:int,target:string}|null
     */
    public function readMagicLink(string $token): ?array
    {
        $json = Craft::$app->getSecurity()->validateData($token);
        if ($json === false) {
            return null;
        }

        $data = Json::decode($json);
        if (empty($data['type']) || empty($data['uid']) || empty($data['exp']) || (int) $data['exp'] < time()) {
            return null;
        }

        return [
            'type' => (string) $data['type'],
            'uid' => (string) $data['uid'],
            'epoch' => (int) ($data['ep'] ?? -1),
            'target' => (string) ($data['target'] ?? ''),
        ];
    }

    /** Signed, tamper-proof: carries the scope's type+uid only — never the secret. */
    public function signScopeToken(Scope $scope): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode([
            'type' => $scope->type,
            'uid' => $scope->uid,
        ]));
    }

    /** Validates + decodes a {@see signScopeToken()} token into its raw `['type' => ..., 'uid' => ...]`. */
    public function readScopeToken(string $token): ?array
    {
        $json = Craft::$app->getSecurity()->validateData($token);
        if ($json === false) {
            return null;
        }

        $data = Json::decode($json);
        if (empty($data['type']) || empty($data['uid'])) {
            return null;
        }

        return $data;
    }

    /**
     * Rebuilds a live Scope from a validated token's [type, uid] — always
     * reads the CURRENT secret from the DB (never from the token), so an
     * edited password, a disabled rule, or a cleared entry secret takes
     * effect immediately.
     */
    public function scopeFromToken(array $data): ?Scope
    {
        $uid = (string) ($data['uid'] ?? '');
        if ($uid === '') {
            return null;
        }

        return match ($data['type'] ?? null) {
            'rule' => $this->ruleScope($uid),
            'entry' => $this->entryScope($uid),
            default => null,
        };
    }

    private function ruleScope(string $ruleUid): ?Scope
    {
        $rule = Plugin::getInstance()->rules->getByUid($ruleUid);
        return ($rule && $rule->enabled) ? $rule->toScope() : null;
    }

    private function entryScope(string $elementUid): ?Scope
    {
        $stored = Plugin::getInstance()->secrets->getForEntry($elementUid);
        // null = never protected; 'disabled' = a tombstone kept only to hold the
        // revocation epoch across a disable→re-enable cycle ({@see \iceboxind\sesame\services\Secrets::disableForEntry()}).
        // Either way the entry is not protected by its own field right now.
        if ($stored === null || $stored['mode'] === 'disabled') {
            return null;
        }

        return new Scope([
            'type' => 'entry',
            'uid' => $elementUid,
            'message' => null,
            'templateOverride' => null,
            'secret' => $stored['secret'],
            'secretMode' => $stored['mode'],
            'epoch' => $stored['epoch'],
        ]);
    }

    private function sessionKey(Scope $scope): string
    {
        return self::SESSION_PREFIX . $this->scopeKey($scope);
    }
}
