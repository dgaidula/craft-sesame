<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use iceboxind\sesame\Plugin;

/**
 * Brute-force throttling for the unlock endpoint (Craft has no general
 * front-end rate limiter). Failed attempts are counted in Craft's data cache
 * and checked BEFORE the password compare, so a flood also can't spend bcrypt
 * CPU.
 *
 * Two buckets, both required (P0.3):
 *  - **per IP + scope** — the tight, everyday limit (`attemptLimit`).
 *  - **per scope, IP-independent** — a higher ceiling that still climbs when an
 *    attacker rotates `X-Forwarded-For` to make every guess look like a fresh
 *    IP (which would otherwise defeat the per-IP bucket entirely on Craft's
 *    default proxy config). The higher threshold is the deliberate trade for
 *    the fact that this bucket is shared: one attacker can trip a scope into a
 *    cooldown, which is preferable to unlimited guesses. (The real fix for a
 *    reverse proxy that hides the client IP is the site's `trustedHosts`
 *    config so `getUserIP()` returns the true client — this is defense in depth
 *    on top of that.)
 *
 * FIXED window, not sliding: the counter key carries the window number
 * (`floor(time() / attemptWindow)`), so each window starts fresh. A steady
 * trickle of organic wrong guesses can therefore never accumulate ACROSS
 * windows into a lockout that no attacker caused — it resets every window —
 * and a determined attacker must re-send a full burst each window rather than
 * hold a scope down with one slow drip.
 */
class Throttle extends Component
{
    /**
     * The IP-independent per-scope ceiling, as a multiple of the per-IP
     * `attemptLimit`. High enough that normal shared-IP traffic (a school, an
     * office behind one NAT) doesn't trip it, low enough to still bound a
     * rotating-IP attacker.
     */
    private const SCOPE_LIMIT_MULTIPLIER = 5;

    public function tooMany(string $ip, string $scopeKey): bool
    {
        $cache = Craft::$app->getCache();
        $settings = Plugin::getInstance()->getSettings();
        $limit = $settings->attemptLimit;
        $w = $this->windowNumber($settings->attemptWindow);

        if ((int) $cache->get($this->cacheKey($ip, $scopeKey, $w)) >= $limit) {
            return true;
        }

        return (int) $cache->get($this->scopeCacheKey($scopeKey, $w)) >= $limit * self::SCOPE_LIMIT_MULTIPLIER;
    }

    /** Records one failed attempt against both buckets for the current fixed window. */
    public function record(string $ip, string $scopeKey): void
    {
        $window = Plugin::getInstance()->getSettings()->attemptWindow;
        $w = $this->windowNumber($window);
        $this->bump($this->cacheKey($ip, $scopeKey, $w), $window);
        $this->bump($this->scopeCacheKey($scopeKey, $w), $window);
    }

    /**
     * Increments one counter as an atomic read-modify-write. Yii's cache has no
     * cross-driver atomic increment, so a Craft mutex serializes the get+set —
     * without it two concurrent failures both read N and both write N+1, losing
     * a count and loosening the limit. Best-effort: if the lock can't be taken
     * within the short timeout, fall through to a plain bump rather than block a
     * request — a rare lost increment only slightly loosens a defense-in-depth
     * limit, whereas holding a web worker does real harm.
     */
    private function bump(string $key, int $ttl): void
    {
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();
        $lockName = "sesame:throttle:{$key}";

        // A throttle must never be able to fatal the unlock flow, so a mutex
        // backend hiccup degrades to a plain (non-atomic) bump.
        $locked = false;
        try {
            $locked = $mutex->acquire($lockName, 1);
        } catch (\Throwable) {
            $locked = false;
        }

        try {
            $count = (int) $cache->get($key);
            $cache->set($key, $count + 1, $ttl);
        } finally {
            if ($locked) {
                try {
                    $mutex->release($lockName);
                } catch (\Throwable) {
                    // Lock auto-expires; nothing to do.
                }
            }
        }
    }

    /** The current fixed-window number; 0 if the window is somehow non-positive (settings enforce >= 30). */
    private function windowNumber(int $window): int
    {
        return $window > 0 ? intdiv(time(), $window) : 0;
    }

    private function cacheKey(string $ip, string $scopeKey, int $w): string
    {
        return "sesame:attempts:{$ip}:{$scopeKey}:{$w}";
    }

    private function scopeCacheKey(string $scopeKey, int $w): string
    {
        return "sesame:attempts:scope:{$scopeKey}:{$w}";
    }
}
