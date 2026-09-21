<?php

namespace iceboxind\sesame;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SetElementRouteEvent;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use iceboxind\sesame\fields\Protect;
use iceboxind\sesame\models\Settings;
use iceboxind\sesame\services\AccessLog;
use iceboxind\sesame\services\Gate;
use iceboxind\sesame\services\Rules;
use iceboxind\sesame\services\Secrets;
use iceboxind\sesame\services\StaticCache;
use iceboxind\sesame\services\Throttle;
use iceboxind\sesame\web\twig\PluginVariable;
use yii\base\Event;

/**
 * Sesame plugin (self-contained, Plugin-Store-bound).
 *
 * Password-protect Craft pages with no template code. A CP-managed rule set
 * (URI/section/entry-type matches) plus a per-entry "Sesame Protection"
 * field gate front-end requests via `Element::EVENT_SET_ROUTE`; a visitor
 * who doesn't already hold a session unlock is routed to a password
 * challenge instead of the page.
 *
 * @property-read Rules $rules
 * @property-read Secrets $secrets
 * @property-read Gate $gate
 * @property-read Throttle $throttle
 * @property-read AccessLog $accessLog
 * @property-read StaticCache $staticCache
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /** Required by every {@see \iceboxind\sesame\controllers\RulesController} action. */
    public const PERMISSION_MANAGE_RULES = 'sesame:manageRules';

    /** PRO. Required by {@see \iceboxind\sesame\controllers\LogController}. */
    public const PERMISSION_VIEW_LOG = 'sesame:viewLog';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    /** Single place the Pro gates ask. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    public static function config(): array
    {
        return [
            'components' => [
                'rules' => Rules::class,
                'secrets' => Secrets::class,
                'gate' => Gate::class,
                'throttle' => Throttle::class,
                'accessLog' => AccessLog::class,
                'staticCache' => StaticCache::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Optional rename: let a site relabel the plugin in the CP (Plugins
        // screen, settings breadcrumb, and — via getCpNavItem — the sidebar).
        // Blank keeps "Sesame". Guarded so a partially-constructed
        // settings model can't blank it.
        $name = $this->getSettings()->pluginName ?? '';
        if ($name !== '') {
            $this->name = $name;
        }

        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerTemplateRoots();
        $this->registerFieldType();
        $this->registerSiteUrlRules();
        $this->registerCpUrlRules();
        $this->registerRequestGate();
        $this->registerBlitzIntegration();
        $this->registerGarbageCollection();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        // parent already uses $this->name (set from the pluginName override in
        // init()); fall back to "Sesame" if it somehow came through empty.
        $item['label'] = $this->name ?: Craft::t('sesame', 'Sesame');
        // parent::getCpNavItem() already points 'icon' at src/icon.svg by
        // convention.

        // The section has no bare "Sesame" index screen of its own — land
        // directly on Rules, the plugin's one CP screen so far.
        $item['url'] = 'sesame/rules';
        $item['subnav'] = [
            'rules' => ['label' => Craft::t('sesame', 'Rules'), 'url' => 'sesame/rules'],
        ];

        // PRO. Lite hides the nav item entirely — LogController also refuses
        // directly (belt-and-suspenders), but there's no reason to advertise
        // a screen Lite can't use. Permission-gated on top of the edition
        // check, same as any other CP nav entry.
        if (
            $this->isPro()
            && Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW_LOG)
        ) {
            $item['subnav']['log'] = ['label' => Craft::t('sesame', 'Access Log'), 'url' => 'sesame/log'];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('sesame/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'isPro' => $this->isPro(),
        ]);
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('sesame', 'Sesame'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_RULES => [
                            'label' => Craft::t('sesame', 'Manage Sesame rules'),
                        ],
                        // PRO. Registered on every edition (permissions are
                        // cheap to declare) — LogController + getCpNavItem()
                        // still require Plugin::isPro() on top of this.
                        self::PERMISSION_VIEW_LOG => [
                            'label' => Craft::t('sesame', 'View Sesame access log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('sesame', PluginVariable::class);
            }
        );
    }

    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['sesame'] = __DIR__ . '/templates';
            }
        );
        // Site (front-end) root is narrowed to just the gate templates. If the
        // whole `templates/` dir were exposed here (as the CP root is), a
        // front-end request to /sesame/rules or /sesame/log would resolve to
        // those CP templates — untidy (they extend the CP layout and get no
        // data) rather than a leak, but there is no reason to route them. The
        // key stays `sesame/gate` so the challenge path (`sesame/gate/challenge`)
        // and any per-rule template override still resolve, and a site's own
        // `sesame/gate/challenge.twig` override still wins via its own root.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['sesame/gate'] = __DIR__ . '/templates/gate';
            }
        );
    }

    private function registerFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = Protect::class;
            }
        );
    }

    private function registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['POST sesame/gate/unlock'] = 'sesame/gate/unlock';
                // PRO. GET — a magic link is just a link to click; actionLink
                // itself 404s on Lite (defense in depth, see GateController).
                $event->rules['sesame/gate/link'] = 'sesame/gate/link';
            }
        );
    }

    /** CP routes for the Rules screen (presentation layer, RulesController) and — PRO — the Access Log. */
    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['sesame/rules'] = 'sesame/rules/index';
                $event->rules['sesame/rules/new'] = 'sesame/rules/edit';
                $event->rules['sesame/rules/<uid:[a-zA-Z0-9\-]+>'] = 'sesame/rules/edit';
                $event->rules['sesame/log'] = 'sesame/log/index';
            }
        );
    }

    // TODO Pro: GraphQL-aware gating + query-filter helpers. Natural seam is
    // a `Gql::EVENT_REGISTER_GQL_TYPES` / resolver hook here (mirroring
    // registerFieldType()'s EVENT_REGISTER_* pattern) plus a
    // `craft.sesame.unprotected(query)` Twig helper on PluginVariable — see
    // PATTERNS.md §8, "GraphQL... exposed per schema/section scope,
    // URI-independent". Not built; GraphQL queries bypass Sesame entirely on
    // both editions today (documented in README's leak-caveats section).

    // TODO Pro: bulk "protect selected" element index action. Natural seam
    // is `Entry::EVENT_REGISTER_ACTIONS`, gated behind `isPro()`, adding a
    // `craft\base\ElementAction` that mints/toggles a Sesame rule for the
    // selected entries. Not built — Lite (and Pro, for now) has no bulk
    // protect action; rules are single-add only via the Rules CP screen.

    /**
     * Retention purge for {{%sesame_access_log}} — mirrors Downtoll's
     * `Submissions::purgeExpired()` / `registerGarbageCollection()` pattern.
     * Fires during `php craft gc` and Craft's scheduled garbage collection.
     * Runs on EVERY edition, not just Pro: only *writing* the log is a Pro
     * feature, so after a Pro→Lite downgrade the rows written while Pro must
     * still age out. `AccessLog::purgeExpired()` no-ops when
     * `accessLogRetentionDays` is 0 or less, and there is nothing to delete on
     * an install that was never Pro, so this is harmless on Lite.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function (): void {
                $this->accessLog->purgeExpired();
            }
        );
    }

    /**
     * Gates front-end entry requests (PATTERNS.md §2). A protected, not-yet-
     * unlocked entry gets routed to the challenge screen in place of its
     * normal template render; everything else is untouched.
     */
    private function registerRequestGate(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_SET_ROUTE,
            function (SetElementRouteEvent $event): void {
                /** @var Entry $entry */
                $entry = $event->sender;
                $scope = $this->gate->isProtected($entry);
                if ($scope === null) {
                    return;
                }

                // A protected URL must never be served from a static cache,
                // whether the visitor is locked out or already unlocked (P0.1):
                // a cached challenge ships a stale CSRF token, and a cached
                // unlocked page is served to everyone with no password. Craft's
                // no-cache headers cover the browser and reverse proxies; Blitz
                // decides cacheability without reading headers and is handled
                // separately in registerBlitzIntegration().
                Craft::$app->getResponse()->setNoCacheHeaders();

                if ($this->gate->isUnlocked($scope)) {
                    return;
                }

                $event->route = ['sesame/gate/challenge', [
                    't' => $this->gate->signScopeToken($scope),
                    'return' => $entry->url ?: $entry->uri,
                ]];
                $event->handled = true;
            }
        );
    }

    /**
     * Static-cache safety with Blitz (P0.1). Vetoes Blitz on
     * `EVENT_IS_CACHEABLE_REQUEST` for any protected URL — the single check
     * that gates both serving a cached copy and writing a new one
     * (`CacheRequestService::getIsCacheableRequest()`, called at
     * `Application::EVENT_INIT`, before Sesame's own gate ever runs). Only
     * registered when Blitz is installed; a no-op otherwise. Sesame has no
     * hard dependency on Blitz.
     */
    private function registerBlitzIntegration(): void
    {
        if (!class_exists(\putyourlightson\blitz\Blitz::class)) {
            return;
        }

        Event::on(
            \putyourlightson\blitz\services\CacheRequestService::class,
            \putyourlightson\blitz\services\CacheRequestService::EVENT_IS_CACHEABLE_REQUEST,
            function (\craft\events\CancelableEvent $event): void {
                if ($this->staticCache->currentRequestIsProtected()) {
                    $event->isValid = false;
                }
            }
        );
    }
}
