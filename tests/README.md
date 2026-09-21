# Sesame test harness

These are **integration tests**. They run the real plugin against a live Craft 5
install in DDEV and assert on real HTTP responses and real database state — there
is no mocking. A unit-only suite would not catch the bugs this plugin has
actually shipped (an encrypt-mode secret that could not persist to a `utf8`
column; a provisional-draft autosave that silently dropped the password). Those
only surface against a real Craft DB, so the harness targets one.

Two kinds of file:

- **`*.php`** — logic/service suites. Fed to `craft shell` (psysh) inside the
  container, so they exercise the services directly against the DB.
- **`*.mjs`** — HTTP drivers. Node scripts that drive the front-end gate and the
  CP actions over real HTTP (cookie jar, CSRF, admin login), and shell into the
  container between steps to mutate server-side state.

## Prerequisites

A local test host. The suites are pinned to Dan's private
[`craft5-plugin-testbed`](../../../github-private/craft5-plugin-testbed) DDEV
project — **never a client site** (Sesame is only ever installed on the testbed
or a throwaway host). To run them elsewhere, change `BASE` and `TESTBED` at the
top of each file.

The host must have:

1. **DDEV up** — `ddev start` in the testbed, reachable at
   `https://craft5-testbed.ddev.site`.
2. **Sesame linked + installed** — the testbed pulls this repo as a Composer path
   repo (`plugins/craft-sesame`). Symlink or copy this repo there and
   `ddev composer reinstall iceboxind/craft-sesame`, then
   `ddev craft plugin/install sesame`. (A path repo does not re-mirror on
   `composer update`; a `reinstall` is required to pull repo edits into the
   host's `vendor/` — see `EFFORT.md`.)
3. **A test section** — a channel `sesameTest`, routed so `/sesame-test/page-one`
   and a child entry `/sesame-test/child` render. Its template must print the
   marker string `REAL PROTECTED CONTENT` when shown, so the drivers can tell the
   real page from the challenge (the challenge carries `id="sesame-password"`).
4. **The Protect field** — a `sesameProtect` (Sesame Protect) field attached to
   the `sesameTest` entry type, for the per-entry draft/epoch suites.
5. **A page-one rule** — a rule matching `sesame-test/page-one` whose primary code
   ("code one") password is `letmein`.
6. **An admin** — user `admin`, password `Password123!`. Override with the
   `CRAFT_ADMIN_PW` env var; the literal is only the testbed default.

`testbed-section.php`, `testbed-rule.php`, and `test-glob.php` seed some of this;
`h-seed-glob.php` / `h-del-glob.php` add and remove a `members/*` glob rule for
the pattern-rule suites.

## Edition

Several suites assert Lite-vs-Pro behaviour. Switching the edition from
`craft shell` does **not** persist unless you flush project config explicitly —
the REPL skips the request-shutdown flush. The helpers do it correctly:

```sh
cd ~/sw/github-private/craft5-plugin-testbed
ddev craft shell < ~/sw/github-public/craft-sesame/tests/h-lite.php   # -> lite
ddev craft shell < ~/sw/github-public/craft-sesame/tests/h-pro.php    # -> pro
```

No `ddev restart` is needed after that (the explicit `saveModifiedConfigData()`
lands for the next request); the older "flip didn't reach the web" note was the
REPL-flush gap, not FPM caching.

## Running

Logic suites (from the testbed directory):

```sh
cd ~/sw/github-private/craft5-plugin-testbed
ddev craft shell < ~/sw/github-public/craft-sesame/tests/entry-epoch-test.php
```

HTTP drivers (from anywhere with Node; they cd into the testbed for `ddev`
themselves):

```sh
node ~/sw/github-public/craft-sesame/tests/epoch-test.mjs
```

Each script prints `PASS`/`FAIL` per assertion and a final
`RESULT: N passed, M failed`, and exits non-zero on any failure — so they compose
in a shell loop. Some CP-action drivers take a seeded rule UID as `argv[2]`
(`p1-authoring`, `p2-ui`, `p2fix`) — seed the rule first (`h-seed-glob.php` prints
its UID) and pass it in.

## Suites and last-green counts

Counts are the last recorded green run (see `EFFORT.md` for the pass that logged
each). Treat them as the expected total, not a contract — re-run and update when
you change behaviour.

| Suite | Kind | Covers | Asserts |
|---|---|---|---:|
| `testbed-gate-test.mjs` | HTTP | core gate spine: challenge → unlock → session persistence | — |
| `entry-epoch-test.php` | logic | per-entry Protect field: store/verify + revocation epoch, disable/re-enable tombstone | 14 |
| `epoch-test.mjs` | HTTP | rule revocation epoch: password change and revoke-all cut off a live session | 13 |
| `pro-epoch-test.mjs` | HTTP | Pro carriers (magic links, remember-me) carry the epoch and revoke with it | 11 |
| `throttle-svc-test.php` | logic | per-IP + per-scope fixed-window buckets; rotating-IP defense; exact counts | 8 |
| `throttle-http-test.mjs` | HTTP | end-to-end cooldown after `attemptLimit` wrong passwords (before bcrypt) | 2 |
| `login-reveal-test.mjs` | HTTP | reveal-password needs an elevated session; writes a `reveal` audit row | 3 |
| `preview-test.mjs` | HTTP | preview bypass only for a logged-in viewer; anon + token stay gated | 4 |
| `item5-test.mjs` | HTTP | Lite authoring gate: pattern rules rejected, exact-URI + password edits allowed | 6 |
| `p1-schedule-test.php` | logic | scheduled lock/unlock window (`protectFrom`/`protectUntil`), fail-closed on bad date | 13 |
| `p1-authoring-test.mjs` | HTTP | schedule authoring is Pro-gated; Lite can't set one and doesn't wipe a grandfathered one | 3 |
| `p2-codes-test.php` | logic | named codes: add/relabel/expiry/revoke/delete/reveal, code-one = rule password, cap | 20 |
| `p2-http-test.mjs` | HTTP | code-one unlock (compat), per-code session revoke, per-code magic-link revoke | 9 |
| `p2-ui-test.mjs` | HTTP | code-management CP actions + the edit-screen code list render (Pro) | 9 |
| `p2fix-test.mjs` | HTTP | Lite can revoke/delete existing codes; add + relabel/expiry stay Pro | 7 |
| `p3-branding-test.php` | logic | branding resolve (per-rule ?? site ?? null) + `sanitizeAccent` | 19 |
| `p3-http-test.mjs` | HTTP | challenge reflects site + per-rule branding; Branding CP screen saves (Pro) | 8 |
| `draft-flow-test.php` | logic | provisional-draft autosave: secret follows the draft, reconciles to canonical on apply, orphan GC | 12 |

The eight original P0 suites (entry-epoch, throttle-svc, throttle-http, reveal,
preview, item5, epoch, pro-epoch) total the "61/61 across 8 suites" the release
notes refer to; the P1 suites (schedule, authoring, codes, branding, draft-flow)
were added as those features shipped.
