<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use iceboxind\sesame\Plugin;
use putyourlightson\blitz\Blitz;

/**
 * Static-cache safety (P0.1). A protected URL must never be served from, or
 * written to, a static cache — locked or unlocked. Craft's own no-cache
 * headers (set in {@see \iceboxind\sesame\Plugin}'s gate handler) cover the
 * browser and reverse proxies, but a full-page cache like Blitz decides
 * cacheability without looking at response headers, session, or cookies, and
 * serves a hit at `Application::EVENT_INIT` before the `EVENT_SET_ROUTE` gate
 * ever runs. So Blitz is told separately, here.
 *
 * Everything is guarded on Blitz being installed and wrapped so a third-party
 * plugin's internals shifting can never fatal a page — Sesame has no hard
 * dependency on Blitz.
 */
class StaticCache extends Component
{
    public function blitzInstalled(): bool
    {
        return class_exists(Blitz::class);
    }

    /**
     * Whether the CURRENT request targets a protected page. Used to veto Blitz
     * on `EVENT_IS_CACHEABLE_REQUEST`, which gates BOTH serving a cached copy
     * and writing a new one (Blitz.php EVENT_INIT). Returning true here makes
     * Blitz skip the protected URL entirely, so the request falls through to
     * the normal lifecycle and Sesame's gate.
     *
     * Resolves the matched element first (covers URI, section, entry-type, and
     * per-entry-field protection in one check); falls back to a URI-pattern
     * match when the element is not yet resolvable at this point in the
     * lifecycle.
     *
     * IGNORES the schedule (P1.1): this asks "could this URL ever be protected?",
     * not "is it protected this second?". A page cached while it was public
     * (before its lock time, or after its unlock time) must never be served once
     * the window flips, and that flip won't purge an already-written cache entry —
     * so a URL any enabled rule protects at ANY time is never cached. The gate
     * itself still honors the schedule.
     */
    public function currentRequestIsProtected(): bool
    {
        $rules = Plugin::getInstance()->rules;

        try {
            $element = Craft::$app->getUrlManager()->getMatchedElement();
            if ($element instanceof Entry) {
                return Plugin::getInstance()->secrets->hasEntrySecret((string) $element->uid)
                    || $rules->anyEnabledRuleMatches($element);
            }
        } catch (\Throwable) {
            // Element not resolvable this early — fall through to the URI check.
        }

        return $rules->uriIsProtected(Craft::$app->getRequest()->getPathInfo());
    }

    /**
     * Drop any statically-cached copies affected by a protection change so a
     * page cached BEFORE it became protected stops being served. Correctness
     * does not depend on this — the cacheable-request veto already refuses to
     * serve a protected URL from cache — but it frees stale entries and avoids
     * a serve-then-veto churn. `clearAll()` is the honest fallback for glob,
     * section, and entry-type rules whose affected URIs aren't enumerable here.
     */
    public function purgeAll(): void
    {
        if (!$this->blitzInstalled()) {
            return;
        }

        try {
            Blitz::$plugin->clearCache->clearAll();
        } catch (\Throwable $e) {
            Craft::warning('Sesame could not clear the Blitz cache: ' . $e->getMessage(), 'sesame');
        }
    }
}
