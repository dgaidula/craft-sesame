# EFFORT — Sesame (built overnight 2026-09-16)

One row per work-pass. Agent metrics (duration/tokens/tool-calls) are EXACT from completion records. Orchestration wall-clock is an estimate (marked ~). Git author ≠ who did the work. Built alongside its reusable starter, `craft-plugin-base` (see that repo's own notes).

| Pass | Agent / model | Role | Scope | Duration | Tokens | Tools | Outcome |
|---|---|---|---|---:|---:|---:|---|
| Market/approaches research | general-purpose / Sonnet 5 | research | 3 referenced approaches + Craft Store competitor/pricing landscape | 196s | 67.3k | 16 | success |
| Craft-5 gating + security research | general-purpose / Sonnet 5 | research | EVENT_SET_ROUTE spine, security, editions/Store — verified vs craftcms 5.11.1 | 408s | 93.8k | 28 | success |
| Downtoll reuse extraction | Explore / Sonnet 5 | research | reusable patterns from the sibling plugin | 168s | 99.4k | 28 | success |
| craft-plugin-base build | builder / Sonnet 5 | builder | tokenized starter + new-plugin.mjs stamper + boilerplate | 337s | 153.0k | 55 | success (self-test passed; fixed a JSON-escaping bug) |
| Sesame Lite engine | builder / Sonnet 5 | builder | rules/gate/secrets/throttle, EVENT_SET_ROUTE, GateController, Protect field, 3 tables | 650s | 177.0k | 56 | success |
| Sesame presentation | builder / Sonnet 5 | builder | CP rules UI, designed password screen, field polish, README/docs | 692s | 216.4k | 101 | success (caught a JS-quoting bug) |
| Sesame Pro flagship | builder / Sonnet 5 | builder | magic links, access log, remember-me, SEOmatic leak fix | 698s | 213.9k | 100 | success |
| Fresh-context security review | verifier / Opus 4.8 [xhigh] | verifier | adversarial security+correctness pass on both repos | 802s | 164.3k | 43 | success (8 findings; refuted all auth-bypass/secret-exposure) |
| Verifier-fix pass | Opus 4.8 (1M)[high], fable-mode | orchestrator | applied fixes #1–#5, #8 directly (surgical edits) | ~35m (est.) | — | — | success |
| Orchestration + live testing | Opus 4.8 (1M)[high], fable-mode | orchestrator | plan, spec, 4 build briefs, CAF install, gate HTTP test 8/8 (×2), encrypt/hash verify, browser screenshot, magic-link test, cleanup | ~3.5h wall (est.) | (session) | — | in progress |

## Failure modes / recoveries (the useful part for estimation)
- **MCP tinker wedged** after a mid-session `plugin/install` (stale autoloader in the long-lived MCP process): "Class not found", then a >120s hang. Recovery: `ddev mysql` + a Node HTTP driver for the gate test; tinker self-recovered later for pure DB-free unit checks. ~15 min.
- **Browser CP login blocked by 1Password** (Vue model not triggered by form_input; JS-exec cross-extension error). Recovery: captured the front-end screen without login (SQL-seeded rule); CP UI accepted as code-verified. ~10 min.
- **Edition flip didn't reach the web** (`project-config/set` + apply left PHP-FPM workers on the cached pre-flip edition) → magic-link live test needed a full `ddev restart` to drop worker/opcache state. The restart itself ran long (large Resilio-synced tree + Mutagen). Notable gotcha for local Pro testing.
- **path-repo re-sync**: `composer update` won't re-mirror an unchanged dev-main ref; `composer reinstall` is required to pull repo edits into the host's vendor.

## Estimation takeaways
- A Lite/Pro Craft-5 plugin on an existing sibling skeleton, via orchestrated Sonnet builders + a fresh-context verifier, is ~4 builder passes + 1 verifier (~55 min agent wall-clock, ~1.1M agent tokens) plus orchestration/live-testing. The reusable base pays for itself from plugin #2.
- Front-loading verified research made the builds near one-shot: no build stalled or needed a redo; the verifier found real but bounded issues (2 fail-opens, 1 open redirect), all fixed surgically.

## Post-review hardening (2026-09-17, single orchestrator, Opus 4.8 [high])
Working `docs/PRO-PRIORITIES.md` top-down. Each row a commit; testing moved to `craft5-plugin-testbed`.

| Pass | Scope | Outcome |
|---|---|---|
| Item 0 | git-init both repos; vendor rename dgaidula→iceboxind (via rename-vendor.mjs) | done — commits 8f808d5, 94aa71e; renamed build 8/8 |
| Item 1 (P0.1) | static-cache: no-cache headers + Blitz IS_CACHEABLE_REQUEST veto (guarded StaticCache) + purge backstop + README | done — commit f47e7ab; veto verified live, getMatchedElement works at init |
| Bonus fix | encrypt-mode secrets base64-encoded (default mode couldn't persist — binary in utf8 col) | done — commit a4a1bf5; caught on the real testbed, missed by the overnight in-memory check |
| Item 2 (P0.2) | revocation epoch: epoch col both tables, session/cookie/link carry epoch, atomic bumps on password change, Revoke-all button; + verifier-found per-entry disable→re-enable monotonicity fix (tombstone) | done — commit 6d6136c; 13/13 rule HTTP + Pro magic-link/remember-me HTTP + 14/14 per-entry service |
| Item 3 (P0.3) | throttle: IP-independent per-scope bucket (5× ceiling) + atomic mutex-guarded increment (hardened to degrade, never fatal) | done — commit eb98657; 8/8 service + 2/2 HTTP cooldown |
| Item 4 (P0.4) | bug sweep, one commit each: magic-link target+ttl cap, purge on every edition, distinct 'link' event, narrow site template root to gate/, templateOverride fallback, reveal-password elevated-session+audit, drop example(), session-id regen on unlock, logged-in-preview bypass | done — commits 9b3763b/30343e4/e6e68be/7bd1768/3865c72/98d3d97/0c9c2b8/4339638/90abf50; each behavior tested on testbed |
| Fresh verifier pass (item 2) | fresh-context adversarial review of the epoch diff | verifier / Opus 4.8: 123k tok, 15 tools, 396s; 2 findings — migration/schemaVersion (accepted: unreleased, baseline is Install.php) + per-entry disable reset (fixed, folded into 6d6136c) |

- **Key lesson:** an in-memory roundtrip is NOT a persistence test. The encrypt-mode bug (default password mode unsavable) only surfaced saving to a real Craft DB on the testbed. Live-test on the testbed from here.
- **Rabbit-hole cost:** ~large chunk of time trying to make Blitz write cache files (never succeeded in any env); the veto DECISION was ultimately proven via a temp-file probe in the handler, not via observed caching. Should have reached for the direct probe sooner.
- Remaining items 2-7 pending (schema/logic changes + bug sweep + Lite line). Context cleared before item 2.

### 2026-09-17 (cont.) — items 2-4 done, single orchestrator Opus 4.8 (1M)[high]
All P0 SECURITY hardening (items 1-4) now complete + verified on the testbed (11 commits this pass: 6d6136c…90abf50). Testbed edition reverted to lite; nothing installed on CAF. Test harnesses live in the session scratchpad (epoch-test.mjs, pro-epoch-test.mjs, entry-epoch-test.php, throttle-svc-test.php, throttle-http-test.mjs, login-reveal-test.mjs, preview-test.mjs).
- **Testbed Pro-edition gotcha:** `switchEdition()` / `savePluginSettings()` from `ddev craft shell` (psysh) do NOT persist — the REPL skips the request-shutdown project-config flush. Force it: `$pc=Craft::$app->getProjectConfig(); $pc->set('plugins.sesame.edition','pro'); $pc->saveModifiedConfigData();`. This is the clean successor to the overnight "edition flip didn't reach the web" note — no `ddev restart` needed, just the explicit flush + verify in a fresh process.
- **Item 4 #9 unrecoverable:** findings #6/#7 of the FIRST security review are not in the repo and that thread isn't available to this session (only the 8-finding summary row survives, above). Left unrecorded — cannot fabricate them.
- Remaining: **5** Lite line (gate pattern rules to Pro in actionSave + README pitch/pricing — packaging judgment), **6** de-client README/comments, **7** final gate test + fresh security pass, then P1.

### 2026-09-17→18 (cont.) — items 5-7 done, single orchestrator Opus 4.8 (1M)[high]
- **Item 5 CODE** `cc9a1a6` — Lite authoring gate (pattern rules → Pro in actionSave; enforcement untouched; edit screen trims + locks grandfathered pattern rules). 6/6 HTTP.
- **Item 6 CODE** `97031f6` — de-cliented comments + CP examples. (README prose pitch = separate copy pass.)
- **Packaging assessment** — Fable clverify (verifier, model fable): 140.8k tok, 34 tools, 436s. Verdicts: keep patterns→Pro; ADJUST pitch to lead with magic links; HOLD $49+$19; fix README editions table (shows patterns ✅ Lite) + "passwords"→singular + soften leak-sealing before public push. Captured in memory [[sesame-plugin-overnight]].
- **Item 7 regression** — 61/61 behavioral assertions across 8 suites re-run against integrated HEAD (entry-epoch 14, throttle-svc 8, throttle-http 2, reveal 3, preview 4, item5 6, epoch 13, pro-epoch 11+1-harness-artifact). No regressions.
- **Item 7 fresh security pass** — verifier (model fable): 250.3k tok, 38 tools, 1094s. Found 1 HIGH + 5 lower, all in-diff ones FIXED + verified:
  | Fix | Commit | Verified |
  |---|---|---|
  | HIGH: preview bypass keyed on unbound nonce + any logged-in identity → gate on `$entry->canView($user)` | `59217f5` | admin bypass + anon gated (HTTP); negative by canView source (Solo 1-user cap blocks live non-viewer test) |
  | MED: throttle sliding-window organic self-lockout → fixed window (window-numbered keys) + README disclosure | `e40e8fa` | 8/8 + 2/2 |
  | LOW: retarget didn't bump epoch → bump on target change (both editions) | `3ba6114` | epoch 0→1 on retarget (Pro) |
  | LOW: regenerateID depended on session already open → `$session->open()` first | `421081d` | epoch 13/13 |
  | LOW: audit didn't record who → nullable `userId` col + write + CP "User" column | `c1db2e6` | reveal row userId=1 |
  - **Accepted (no change):** migration/schemaVersion (unreleased baseline = Install.php).
  - **Flagged OUTSIDE the diff for pre-launch (NOT fixed — pre-existing / need live CP test):** (1) PLAUSIBLE launch-blocker — per-entry password in Craft's PROVISIONAL-DRAFT autosave: password lands in a row keyed by the draft uid; applying the draft saves canonical with password===null → markProtectedWithoutSecret → protection on with empty secret, password silently lost (fail-closed). Needs one live CP test with the Protect field. (2) Multi-site translatable Protect field: last-propagated site's enabled=false tombstones the shared uid-keyed row → other sites fail OPEN. (3) DummyCache data cache makes the throttle a no-op.
- Testbed reinstalled once (userId schema), reverted to lite, page-one rule clean; nothing on CAF.
- **All P0 (items 1-7 code) COMPLETE.** Remaining = README editions/pitch COPY (Dan+Fable verdicts ready), README prose de-client, dangling design-doc comment refs (doc-intent call), the 3 pre-launch flags above, then P1 (scheduled lock/unlock → named codes → branding).
