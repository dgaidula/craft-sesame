<?php

namespace dgaidula\sesame\services;

use Craft;
use craft\base\Component;
use dgaidula\sesame\Plugin;

/**
 * Brute-force throttling for the unlock endpoint (PATTERNS.md §5 — Craft has
 * no general front-end rate limiter). A counter in Craft's data cache keyed
 * per IP+scope, checked BEFORE the password compare so a flood also can't
 * spend bcrypt CPU.
 */
class Throttle extends Component
{
    public function tooMany(string $ip, string $scopeKey): bool
    {
        $count = (int) Craft::$app->getCache()->get($this->cacheKey($ip, $scopeKey));
        return $count >= Plugin::getInstance()->getSettings()->attemptLimit;
    }

    /** Records one failed attempt, (re)starting the window's TTL on the first hit. */
    public function record(string $ip, string $scopeKey): void
    {
        $cache = Craft::$app->getCache();
        $key = $this->cacheKey($ip, $scopeKey);
        $count = (int) $cache->get($key);
        $cache->set($key, $count + 1, Plugin::getInstance()->getSettings()->attemptWindow);
    }

    private function cacheKey(string $ip, string $scopeKey): string
    {
        return "sesame:attempts:{$ip}:{$scopeKey}";
    }
}
