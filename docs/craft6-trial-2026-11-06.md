# Craft 6 compatibility trial: Sesame (2026-11-05/06)

A throwaway Craft **6.0.0-alpha.20** site (`~/sw/craft6-trial`, DDEV `craft6-trial`, PHP 8.5, MySQL 8.4) ran all five of Dan’s plugins through `craftcms/yii2-adapter`. This note keeps Sesame’s part so it is not lost; the full report, with versions, setup gotchas and every citation, is `monetization-ops/research/craft6-trial-2026-11-06.md` (a copy of `~/sw/craft6-trial/REPORT.md`). Craft 6 is alpha: this is a snapshot, to be re-run at beta. Recommendation (2026-11-06): no port work until Craft 6 reaches beta, then one port pass per plugin from this note.

| Plugin | Verdict | Top blocker | Porting |
|---|---|---|---|
| Sesame (`56daa08`) | Partly works | `Protect` field TypeError on construction; magic links 500 | small |

**Finding #1, every plugin:** `composer.json` requires `craftcms/cms ^5` (or `^4.5.0 || ^5.0.0`), which blocks install on Craft 6; Craft 6 and the adapter need PHP ^8.5.

## Sesame — small
Tested: install, settings save, CP rules screens (200); the password challenge on a protected page, unlock and session
persistence. Its own `tests/run-all.mjs` (copied, host and paths replaced): 169 passed, 35 failed across 19 suites —
all 7 logic suites passed; failing: login-reveal, item5, p2-http, p2-ui, p2fix, pro-epoch, section-uid-http.
**Broke:**
1. `src/fields/Protect.php:43` returns `[self::TRANSLATION_METHOD_NONE]`; Craft 6 expects `TranslationMethod` enum cases
   (`vendor/craftcms/cms/src/Field/Field.php:279-282`), so `new Protect([...])` throws a TypeError and the field cannot
   be created. Returning `\CraftCms\Cms\Field\Enums\TranslationMethod::None` fixed it in the trial copy.
2. `src/Plugin.php:252` — `GET /sesame/gate/link?t=…` returns 500 (`Missing required parameters`); `actionLink(string $t)`
   does not receive the query parameter through a site URL rule (the `/actions/…` form works).
3. Yii `ForbiddenHttpException` (“Pro feature”, `LogController.php:29`, `BrandingController.php:30`) renders as 500, not
   403.
4. Needs `securityKey` (above) for `encryptByKey`.
Harness: `login-reveal` gets 419 (Craft 6 uses Laravel `_token`/`XSRF-TOKEN`). item5, p2-ui, p2fix and
section-uid-http were not diagnosed individually.

## Deprecations naming plugin code
From the `deprecationerrors` table and `storage/logs` (`scratch/deprecations.mjs`):

| Plugin file:line | Deprecated call | Craft 6 change |
|---|---|---|
| sorts `src/Plugin.php:212` | `View::registerTwigExtension()` → `Twig::registerExtension()` | CHANGELOG 6.0.0-alpha.1, Twig section (line 3136) |
| sorts `src/Plugin.php:164`, downtoll `src/web/twig/DowntollVariable.php:36`, videocinch `src/Plugin.php:226` | `View::renderTemplate()` → `TemplateManager::renderTemplate()` / `template()` | same section (line 3137) |
| sorts `src/services/EngineService.php:168`, `ContentFixer.php:225` | `Craft::$app->sites` → `app(Sites::class)` | line 3095 |
| sorts `src/services/ContentFixer.php:167` | `Craft::$app->getIsMultiSite()` → `Sites::isMultiSite()` | line 3095 |
| sorts `src/services/AuditScanner.php:42` | `EntryQuery::orderBy()` with an array | `ElementQuery.php:870`; CHANGELOG lines 1071, 1080 |

## Shared blockers and patterns
1. Composer: all five hit `craftcms/cms ^5`; Craft 6 needs PHP ^8.5.
2. The adapter needs `-W` on today’s starter.
3. Yii-era API use per plugin (downtoll/lithocinch/sesame/sorts/videocinch): `Craft::$app->` 43/16/79/27/13;
   `use yii\` 10/5/12/6/3; `use craft\` 53/33/72/52/25; `Event::on` 9/6/11/8/5. All of it loaded.
4. **Where the adapter does not bridge** (each seen here): a typed-property change (`TranslationMethod`); a changed core
   call convention (`setAttributes`); a removed library class (`FieldArgument`); the response/header model
   (`{% header %}`); DI model defaults (`Craft::$container->set(ImageTransform::class…)`); route-rule parameter binding;
   exception-to-status mapping (403 → 500). A grep for these across the other plugins is a cheap first step.
5. Deprecations `View::renderTemplate`, `Craft::$app->sites`, `getIsMultiSite`, `registerTwigExtension` appear in 3 of 5.
6. The shared test harness (Sorts, Sesame) relies on `ddev craft shell` and Yii REPL semantics, a `queue` table, Craft 5
   CSRF and login, CP HTML scraping, `config/project` (Craft 6: `config/craft/project`), and hard-coded hosts and paths.
7. `config/craft/<handle>.php` plugin config files are picked up; multi-environment config files are deprecated.
