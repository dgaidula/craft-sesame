# Sesame — what to build next (review of 2026-09-17)

Handoff for the orchestration thread. Written after a pricing review of the whole
Craft plugin line (Dan’s private `monetization-ops/plans/craft-plugins.md`, section
“Edition and pricing review — 2026-09-17”), a read of this repo, and a fresh-context
adversarial verifier pass over every claim below. Status markers: **[verified]** means
read in source today at the cited line; **[source-only]** means confirmed by reading
third-party source but not reproduced live; **[judgment]** means a call, not a fact;
**[Dan]** means blocked on a decision that is his.

The short version: **the next work is not a Pro feature.** There is a cache bypass that
breaks the core promise on the sites most likely to pay, a revocation gap that is a
security property rather than a Pro feature, and a throttle that can be walked around on
Craft’s default config. All of it is cheap now — nothing is released, the schema is
install-only — and expensive after the first submission.

## Work queue — do these in order, then move on to Pro

Every item is specified below with file and line. Nothing here is blocked on a decision
any more: Dan settled the Lite line and the vendor (`iceboxind/`, repos at `dgaidula`) on 2026-09-17.

- [ ] **0.** `git init` this repo and `craft-plugin-base` and commit the current state, so every change below is its own reviewable commit. **Then move the vendor to `iceboxind`** as the first change, with the tool built for it — dry run, read the output, then apply:

  ```
  node ~/sw/github-public/craft-plugin-base/bin/rename-vendor.mjs --plugin ~/sw/github-public/craft-sesame --from dgaidula --to iceboxind
  node ~/sw/github-public/craft-plugin-base/bin/rename-vendor.mjs --plugin ~/sw/github-public/craft-sesame --from dgaidula --to iceboxind --write
  ```

  It renames the package and the namespace (43 `namespace`/`use` lines) and **leaves every `github.com/dgaidula/craft-sesame` URL alone** — the repo stays under `dgaidula`. It will warn that `fields/Protect.php` is a class Craft persists in project config: Sesame has never been released or deployed — its only install was the local DDEV of a client site used as the test host — so **no upgrade migration is needed**: uninstall and reinstall there (`composer.json` `require` key → `iceboxind/craft-sesame`, `composer reinstall`). **That host is a client’s repo.** It was verified clean of Sesame on 2026-09-17 (no `require`, no project-config entries); keep it that way — never commit Sesame’s `composer.json`, `composer.lock`, or `config/project` changes there, and uninstall when a test session ends. Lint with `/opt/homebrew/bin/php -l` (a bare `php` inside a script is MAMP’s 7.4 on this machine) and re-run the gate HTTP test. Downtoll went through the same move on 2026-09-17 and is the worked example, including the migration Sesame does not need.

- [ ] **1.** Static-cache safety — headers + Blitz `cachingEnabled = false` in the route handler, a veto on serving stale copies, purge on protection change, README section, **live Blitz test in both delivery modes** (P0.1).

- [ ] **2.** Revocation epoch in both tables, new session-value shape, automatic bump on password change (P0.2).

- [ ] **3.** Throttle: add the IP-independent per-scope counter; make the increment atomic (P0.3).

- [ ] **4.** Bug sweep, one commit each (P0.4): magic-link target + `ttlDays` cap · access-log purge on every edition · distinct `link` log event · narrow the site template root to `gate/` · validate `templateOverride` · elevated session + audit row on reveal-password · remove `PluginVariable::example()` · regenerate the session id on unlock · decide and test Live Preview / token / logged-in-editor behaviour.

- [ ] **5.** Lite line: authoring gate for pattern rules in `RulesController::actionSave()`; README editions table and pitch rewritten (P0.5).

- [ ] **6.** Housekeeping: de-client the README and code comments; write down findings #6 and #7 from the first security review if the thread still has them (P0.6).

- [ ] **7.** Re-run the full gate HTTP test and a fresh-context security pass over the diff. **Then** P1, in order: scheduled lock/unlock → named codes → branding.

## Why the order looks like this

- **The niche is small.** The one paying comparable, Template Guard, is $29 + $9/yr with 259 active installs since 2021-07. Speakeasy is free with 13 installs. Knock Knock (Verbb, free, 3,436 installs) already gives away URL-regex protection with one site-wide password. A realistic revenue ceiling is hundreds to low thousands of dollars a year, so: build the cheap, strong upgrade triggers; defer the expensive ones until there is demand. **[verified]** numbers, **[judgment]** ceiling.

- **The one-way door.** A feature can move from Pro to Lite at any time, and never back once real sites depend on it. So the Lite line is settled before the first Store submission, and a feature you are unsure about starts in Pro.

- **Edition gates control authoring and convenience, never enforcement.** Anything protective keeps working if the edition drops to Lite. `Rules::match()` has no edition check today (`services/Rules.php:126–149`) — keep it that way. All 13 current `isPro()` call sites fail closed for access. **[verified]**

- **Security hygiene is never paywalled.** The SEOmatic mitigation is rightly in both editions; the cache fix, the revocation epoch, and the throttle hardening below belong there too.

## P0 — before any Pro work, and before submission

### 1. Static-cache safety (both editions) — critical on Blitz sites **[source-only]**

Sesame serves the real page at the real URL with a 200 once unlocked, and the challenge
at that same URL with a 200 when locked, and sets no cache headers anywhere.

- Blitz (`putyourlightson/craft-blitz`, develop / 5.13.2) decides cacheability without looking at response headers, session, or cookies (`CacheRequestService.php:129–190`, `:195–214`). When it caches a response it **overwrites** `Cache-Control` with `public, s-maxage=31536000, max-age=0` and strips cookies (`:851`, `:882`; `SettingsModel.php:439`). Cached content is served at `Application::EVENT_INIT`, before routing, so the `EVENT_SET_ROUTE` gate never runs on a hit (`Blitz.php:317–336`).

- Realistic bypass: an editor saves a protected entry → Blitz clears that URI → an unlocked member reloads → the real page is cached and served to everyone, no password. If the challenge is cached first instead, the cached CSRF token breaks unlock for every visitor. (The scope token has no expiry or nonce — `Gate.php:174–180` — so that part is not stale.)

- Off Blitz the exposure is lower but not nil: the unlocked page currently relies on PHP’s default `session.cache_limiter = nocache` header, which is a host default and not this plugin’s design. The challenge gets no-cache headers only as a side effect of `csrfInput()` (`Request::getCsrfToken()` → `setNoCacheHeaders()`), and not at all if the site uses `asyncCsrfInputs`.

Build:

- In the existing `EVENT_SET_ROUTE` handler, whenever `$scope !== null` — **locked or unlocked** — call `Craft::$app->getResponse()->setNoCacheHeaders()` and, behind the same `class_exists` + try/catch guard as the SEOmatic shim, set `Blitz::$plugin->generateCache->options->cachingEnabled = false` (the PHP form of `craft.blitz.options`, honoured at `GenerateCacheService.php:571`).

- Veto **serving** a stale cached copy: a handler on Blitz’s `EVENT_IS_CACHEABLE_REQUEST` (fires at init, before an element is matched, so resolve it yourself — `getUrlManager()->getMatchedElement()`; unverified at that point in the lifecycle) or `EVENT_BEFORE_GET_RESPONSE`.

- **Purge on change**: when a rule is saved, deleted, enabled/disabled, or reordered, and when a Protect field value changes, clear the affected URIs from Blitz (`refreshCache->refreshSiteUris()` / `clearCache->clearUris()`; `clearAll()` is the honest fallback for glob, section, and entry-type rules). Without this, a page cached before it became protected keeps being served.

- README: a “Static caching” section. With Blitz server-rewrite delivery PHP never sees the request, so recommend adding protected patterns to Blitz’s `excludedUriPatterns` as well.

Done means: a live test with Blitz installed in the DDEV host, in both PHP delivery and
rewrite mode, covering (a) unlocked page never cached, (b) challenge never cached,
(c) a page cached before protection stops being served after the rule is saved,
(d) the same three with scheduling once P1.1 exists. A 401/403 on the challenge would
also defeat caching, but servers with `fastcgi_intercept_errors` would replace the
challenge body — treat it as an option, not the fix.

### 2. Revocation epoch (both editions) — do it now while the schema is unreleased **[judgment, design verified against the code]**

The README admits that changing a rule’s password does not cut off anyone holding a
session, a remember-me cookie, or a magic link. “A password change locks people out” is a
security property, not a Pro feature.

- Add an integer `epoch` column to `{{%sesame_rules}}` **and** `{{%sesame_entry_secrets}}` (the latter has no Pro columns today — `Install.php:70–78` — so per-entry passwords would otherwise keep the weakness).

- Bump it automatically on password change and on an explicit “revoke all access” action. Use `epoch = epoch + 1` in SQL: `Rules::save()` does a full-row update (`:63–80`) and would clobber a concurrent bump.

- The session value changes from a bare `true` (`Gate.php:68`) to `{epoch, codeId}`; `isUnlocked()` compares against the live epoch. Remember-me cookies and magic links carry the epoch inside the signed payload. The **scope token does not** carry a code id — the code is unknown until verify.

- Doing this after release means a migration plus a one-time mass re-challenge. Now it is free.

### 3. Throttle hardening (both editions) — medium **[verified]**

- The key is `getUserIP()` (`GateController.php:99`). On Craft’s default proxy config a client that rotates `X-Forwarded-For` can get unlimited guesses; behind an untrusted proxy the opposite happens — every visitor shares one bucket and one attacker locks everyone out. (Craft’s `trustedHosts` default expanding to “any” is from the verifier’s memory — confirm against `GeneralConfig.php`.)

- Add an IP-independent per-scope counter with a higher threshold, alongside the per-IP one. Make the increment atomic — `Throttle.php:25–30` is a get-then-set.

### 4. Bug sweep **[verified unless noted]**

- **Magic links to an exact-URI rule land on the home page.** `RulesController.php:160` passes the bare pattern (`members/handbook`) as the target; `GateController::safeReturn()` (`:157–169`) accepts only a leading `/` or a same-host full URL. Almost certainly a regression from the open-redirect fix, and it contradicts `README.md:117–119`. Build the target with `UrlHelper::siteUrl()` or prefix the slash. Cap `ttlDays` while there (`:152` is unbounded).

- **Access-log purge stops on Lite** (`Plugin.php:276`): after a downgrade, IP and user-agent rows persist indefinitely. Purge on every edition; only *writing* is Pro.

- **Magic-link clicks are logged as `unlock`** (`GateController.php:76`). Give them a distinct `link` event so mail-scanner and chat-unfurler prefetches do not pollute the audit trail.

- **The whole template directory is a site template root** (`Plugin.php:199–205`), so `/sesame/log` and `/sesame/rules` route on the front end. They extend the CP layout and their data comes from controllers, so this is untidy rather than a leak — but narrow the site root to `templates/gate/`.

- **`templateOverride` is validated only as `string, max 255`** (`Rule.php:60`). A bad path 500s the challenge — fails closed, but lets anyone with `sesame:manageRules` take a page down. Validate with `doesTemplateExist()` in site mode and fall back to the default. Path traversal unchecked.

- **Reveal-password** (`RulesController.php:175–184`) has no `requireElevatedSession()` and leaves no audit record.

- `PluginVariable` still ships the starter’s `example()` method. `unlock()` does not regenerate the session id.

- **Live Preview / token requests / logged-in editors**: there is no bypass, so previewing a protected entry probably shows the challenge (unverified). Decide the intended behaviour and test it.

- Findings #6 and #7 of the first security review are not recorded anywhere in this repo. If the thread still has them, write them down here.

### 5. The Lite line — **decided by Dan, 2026-09-17**

Move **pattern rules** — `*` globs, whole-section,
whole-entry-type — to Pro; Lite keeps the per-entry Protect field and exact-URI rules,
each with its own password, all CP-managed and editable on production.

- For it: zero build cost, reversible, and it is the asymmetric choice — it can move to Lite later if installs lag, and could never move the other way.

- Against it: Knock Knock already gives URL-regex protection away, so “patterns” alone is a weak pitch. **Sell Pro as “many protected areas, each with its own passwords, editable on production, future pages included,”** not as “patterns.” And a Lite that is too thin gives up the install lever a free tier exists for.

- Do not describe Lite as a complete replacement for Template Guard: Sesame gates Entry routes only (`Plugin.php:291`); template-only routes, categories, and Commerce products are outside it.

Gate **authoring only**, in `RulesController::actionSave()`: on Lite,
reject a pattern rule that is new, or whose `matchType`/`pattern` is changing. Exact URI
is `matchType === 'uri'` with no `*`. Lite must still allow password change,
enable/disable, delete, and reorder on existing pattern rules. Trap: the inline script in
`templates/rules/_edit.twig` keeps exactly one of the three pattern inputs named
`pattern`; a Lite-trimmed form may post an empty pattern and block a password rotation.

Proposed price: Pro **$49 + $19/yr**. Defensible and reversible; unmeasured. **[judgment]**

### 6. Housekeeping

- Neither this repo nor `craft-plugin-base` is under git. `git init` both.

- `composer.json` was set deliberately on 2026-09-17, before the handoff — leave these three as they are: `extra.developer` “Danniel T. Gaidula”, `extra.developerUrl` `https://iceboxind.com`, `support.email` `support@iceboxind.com`. The vendor rename in step 0 does not touch them. `documentationUrl` stays on the GitHub README until `iceboxind.com/sesame` exists.

- Vendor: **`iceboxind/`** for the composer package and the PHP namespace; the GitHub repo stays `dgaidula/craft-sesame` (Dan, 2026-09-17 — the three names are independent, and lithocinch and videocinch already combine them this way). The rename is step 0 of the work queue.

- The README is written in one client’s vocabulary (“email a district a link”). Make the examples generic before the listing is public. Same for the code comment citing “guardrail 7 in the consuming site’s CLAUDE.md”.

## P1 — Pro features, in this order

1. **Scheduled lock/unlock.** Small, strong trigger (embargoes, launches, events). One `unlockUntil` column cannot express both directions — use a start and an end. **It interacts with P0.1:** a page cached legitimately before its lock time keeps being served afterwards, so either veto caching for any URL matched by an enabled rule regardless of schedule, or purge at the transition. Evaluate in the server’s timezone explicitly; fail closed on an unparseable date.

2. **Multiple named codes per rule** — label, expiry, revoke, with the code recorded on every access-log row and magic links minted per code (“send District A its own link; revoke it alone”). This is the real operate-at-scale feature and it builds directly on the P0.2 epoch. Add `codeId` to the log table. In hash mode N codes means N bcrypt verifies per attempt — the throttle runs first, but cap N. **Links must not be single-use**: mail scanners and chat unfurlers prefetch them.

3. **CP-editable screen branding** — logo, heading and intro copy, accent colour; site default plus per-rule override; stored in the plugin’s own table so it is editable on production. Ranked third, not first: Lite already has site-wide and per-rule template overrides, and the buyer is usually a developer who can use them.

## P2 — only on a demand signal

- **reCAPTCHA v3.** A cheap port from Downtoll, but a weak trigger: the throttle already exists.

- **Automatic leak-sealing and GraphQL-aware gating.** The one differentiator nobody else has, and the README’s stated reason for Pro — but large, and risky in a security product: global element-query modification, performance, and a false sense of safety if it is incomplete. Not before launch in a niche this size. Instead ship a small honest helper **in both editions** now: `craft.sesame.isProtected(entry)` and a query filter that excludes protected entries from listings, sitemaps, and feeds. Then soften `README.md:206–208` (“Sealing these paths … is exactly what Sesame Pro is for”) to roadmap language.

- **Bulk “protect selected” element action.** Last, or dropped: pattern rules already do bulk.

- **Access-log privacy.** Rows hold raw IP and user agent. An anonymize-IP setting is cheap and removes a procurement objection.

## Still unknown

- Nothing about Blitz was reproduced live. The three unknowns that matter: whether `getMatchedElement()` works inside an init-time veto; which response gets cached first under rewrite delivery and cache warming; which purge call is practical for pattern rules.

- Whether a Lite without pattern rules pulls installs when Knock Knock gives regex protection away free.

- `Secrets::verify()` and the `markProtectedWithoutSecret` path were not read in this pass.

## Pre-launch flags — design verdicts (Fable, 2026-09-19)

Written after reading the three flags in `EFFORT.md`, the current `fields/Protect.php`
and `services/Secrets.php`, and the relevant Craft 5.10.11 source in the testbed’s
`vendor/`. The API names below were checked there, not recalled. The repro on the testbed
is still worth doing for (1); the fix for each is settled enough to build straight after.

### (1) Provisional-draft autosave loses the password — confirmed by construction, fix it

`Protect::afterElementSave()` keys the secret by `$element->uid` and never asks whether
the element is a draft or a revision. Craft’s edit screen autosaves to a **provisional
draft** — a separate element with its own UID — so the typed password lands in a row keyed
by the draft. `Drafts::applyDraft()` then saves the canonical through
`updateCanonicalElement()` (`services/Drafts.php:330`): the content column (`enabled`)
copies over, the transient `password` does not, so the canonical save hits the
“enabled, no password, none stored” branch and calls `markProtectedWithoutSecret()`.
Result: the page is locked and nobody, including the editor, can open it. Fail-closed, but
a launch blocker — this is the first thing an editor does.

Build (both editions):

- In `afterElementSave()`: `if (ElementHelper::isRevision($element)) return;` — revisions are snapshots and must never write, read, or tombstone a secret. (`craft\helpers\ElementHelper::isRevision()`, `isDraft()`, `isDraftOrRevision()` — `helpers/ElementHelper.php:499–547`.)

- Keep storing a draft’s posted password under the **draft’s** UID (as now — it must not change the live page before publish).

- Add a handler on `craft\services\Drafts::EVENT_AFTER_APPLY_DRAFT` (`services/Drafts.php:361`). The `DraftEvent` carries `$event->draft` and `$event->canonical` (`events/DraftEvent.php:24, 49`). Move the secret row from the draft’s UID to the canonical’s UID — an upsert that **replaces** whatever the canonical save just wrote, including the protected-without-secret placeholder — and bump the canonical’s epoch if the secret actually changed. The event fires *after* the canonical save inside `applyDraft()`, so this ordering is what makes the end state correct.

- On draft discard, delete the draft-keyed row (`Elements::EVENT_AFTER_DELETE_ELEMENT` where `ElementHelper::isDraft()`), and add a GC pass that removes rows whose UID no longer matches any element — orphans from drafts that were never applied.

- Also: when a **canonical** save arrives with `enabled = true`, `password = null`, and no secret stored, and it is not the apply path, that is an editor who turned protection on without typing a password. Fail closed as now, but say so in the CP — a validation error on the field (“Enter a password, or turn protection off”) beats a silently locked page.

Test on the testbed: type a password in the field, wait for the autosave, then Save; open the page as a visitor; the typed password must unlock it. Then discard a draft and confirm no row is left behind.

### (2) Multi-site fail-open — close it structurally, do not patch the race

The flagged scenario needs the field to be *translatable*. Craft offers that setting for
any field whose value lives in the content column: `Field::supportedTranslationMethods()`
returns every method unless `dbType()` is null (`base/Field.php:286–296`), and
`Protect` stores `{enabled}` there. Protection is a property of the entry, not of one of
its translations, so make the field untranslatable by declaration:

```php
public static function supportedTranslationMethods(): array
{
    return [self::TRANSLATION_METHOD_NONE];
}
```

With that, `enabled` propagates identically to every site, the shared UID-keyed row is
never tombstoned by one site while another still expects it, and the per-site password
question is closed rather than deferred. If per-site passwords are ever wanted, key the
table by `(elementUid, siteId)` then — not now. Also stop `disableForEntry()` from being
reachable from a non-canonical save (covered by the revision/draft guards above).

### (3) DummyCache makes the throttle a no-op — detect now, remove the dependency in P1

A brute-force limiter whose precondition is “the site’s cache is real” has a silent
failure mode, and `yii\caching\DummyCache` is a legitimate config. Two steps:

- **Now (cheap):** at plugin init, if `Craft::$app->getCache() instanceof \yii\caching\DummyCache`, log a warning and show a persistent notice on Sesame’s settings screen: “Brute-force throttling is disabled because this site’s cache component is DummyCache.” Say the same in the README’s throttle bullet.

- **P1, before scheduling if you want a throttle with no config dependency:** a small `{{%sesame_attempts}}` table — `(bucketKey, windowStart, count)` with a unique key on `(bucketKey, windowStart)` — updated with a single `INSERT … ON DUPLICATE KEY UPDATE count = count + 1` (MySQL) / `ON CONFLICT … DO UPDATE` (Postgres), rows purged in GC. That also makes the throttle deterministic in the HTTP tests. Keep the cache path as the fast default if you like; the DB path is the fallback when the cache is a dummy.

### The dangling design-doc reference — recommendation

`services/Gate.php:110` still cites `SESAME-PRO-BRIEF.md §C`, a document that is not in
this repo. In a public repo a citation to a file nobody can open reads as an unfinished
edit. Strip the citation and keep the sentence’s substance — the paragraph already explains
the tradeoff in full. Only commit the brief itself under `docs/` if it is client-clean
and you want it public; otherwise the reference goes.

### What this pass changed

`README.md` only: the editions table now shows the decided Lite line (pattern rules are
Pro; exact-URI rules and the field are Lite, both with their own passwords), the pitch
reads “running access to whole areas of a site,” competitor names are out of the table,
the client vocabulary is out of the prose, the leak-sealing promise is roadmap language
behind the three ordered Pro features, and prose punctuation is typographic (code spans and
blocks untouched — verified). Please re-read the “Revocation” and “only creating or
changing one needs Pro” claims against the code you shipped for P0.2 and P0.5; the wording
follows the spec, not a fresh read of the diff.
