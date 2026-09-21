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
