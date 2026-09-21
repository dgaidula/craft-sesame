# Sesame

**Say the word.** Password-protect any Craft page — no template code, no developer.

A Craft 5 plugin by Danniel T. Gaidula.

## Requirements

**Craft CMS 5.0+ · PHP 8.2+.** Sesame is a Craft 5 plugin and does not run
on Craft 4.

## What it does

1. **Rules, managed in the control panel** — protect a page by its URI,
   behind its own password, from Sesame → Rules. No field layout to touch,
   no template to edit: the fastest way to gate a semi-hidden page. Pro
   extends a rule from one page to a whole area (a `*` pattern, a section,
   an entry type), so pages added later are covered too.
2. **Per-entry protection** — a *Sesame Protection* field an editor can add
   to any entry type, for the one-off page that needs its own password
   instead of sharing a rule’s.
3. **Gates the real page** — hooks Craft’s own routing
   (`Element::EVENT_SET_ROUTE`), so a locked entry never reaches its normal
   template until the visitor unlocks it. There is no separate “protected
   copy” to keep in sync.
4. **Stores the secret encrypted-at-rest by default** (reversible, so an
   admin can reveal and re-share a page code later) — or bcrypt-hashed
   (write-only) if you turn that on in settings.
5. **Throttles guesses** — failed attempts are rate-limited before the
   password is even compared, in a fixed window, per IP *and* by a higher
   IP-independent per-page ceiling (so an attacker rotating `X-Forwarded-For`
   can’t buy unlimited tries). The per-page ceiling is shared, so a determined
   attacker can push a single page into a short cooldown for everyone — the
   deliberate trade for closing the rotation bypass; the real fix for a proxy
   that hides client IPs is your site’s `trustedHosts` config.
6. **Ships a real password screen** — a small, deliberate design, themeable
   light/dark, fully overridable per site or per rule.

## Editions

Sesame ships two editions. Lite is free, and complete for the job most sites
have: one page, or a handful, each behind its own password, set up from the
control panel. Pro is for running access to whole areas of a site, and for
the people who need to get in without a password.

| | Lite (free) | Pro |
| --- | :---: | :---: |
| Per-entry protection field | ✅ | ✅ |
| Rules by exact URI, each with its own password, editable on production | ✅ | ✅ |
| Rules by `*` pattern, section, or entry type — pages added later are covered | — | ✅ |
| The default password screen, overridable per site or per rule | ✅ | ✅ |
| Passwords encrypted at rest; bcrypt optional | ✅ | ✅ |
| Session unlock, brute-force throttle, SEOmatic leak mitigation | ✅ | ✅ |
| Revocation: changing a password locks out everyone already in | ✅ | ✅ |
| Shareable magic links — email someone a link instead of a password | — | ✅ |
| Access log — who unlocked what, and when, with retention | — | ✅ |
| Remember me — unlocks that survive a browser restart, per rule | — | ✅ |
| Scheduled lock and unlock, by date | — | roadmap |
| Multiple named codes per rule, each with a label, an expiry, and a revoke | — | roadmap |
| Control-panel branding of the password screen | — | roadmap |

The roadmap rows are the next three Pro features, in that order. A pattern
rule created on Pro keeps protecting its pages if the site later moves to
Lite; only creating or changing one needs Pro.

### Rename it in the control panel

Sesame can present under any name you like — the sidebar nav, the Plugins
screen, and the settings breadcrumb all follow **Settings → General →
Plugin name** (blank = “Sesame”). Set it in `config/sesame.php` so it holds
with `allowAdminChanges` off:

```php
// config/sesame.php
return [
    '*' => [
        'pluginName' => 'Something Else',
    ],
];
```

## Configuration

Sesame offers two independent ways to protect a page — use either, or both:

- **Sesame → Rules** — a control-panel section backed by the plugin’s own
  table, so rules stay editable on production even with `allowAdminChanges`
  off. A rule matches an exact URI (`partners/handbook`); on Pro it can also
  match a `*` pattern (`partners/*`), a section handle, or an entry-type
  handle. Each rule carries its own password, an optional prompt message, and
  an optional template override. This is the zero-field-layout way to gate a
  page, and the one to reach for first: it needs no changes to the entries
  themselves.
- **Sesame Protection field** — add the field to an entry type’s layout,
  then flip it on per entry with its own password. Useful when one specific
  entry needs a password independent of any rule (or a different one than
  the rule that would otherwise match it — the field always wins). The
  password is never stored in the entry’s content column; only the
  on/off flag is, so it never appears in element exports, revisions, or
  GraphQL.

A rule’s password can be viewed again later from its edit screen (**Reveal
current password**) as long as it’s stored in the default encrypt-at-rest
mode — turn on **Settings → General → Hash passwords** to store bcrypt
instead, which trades that recall away for write-only storage.

## Pro features

Pro adds the tools for running access, not just setting it.

- **Shareable magic links.** On a saved rule’s edit screen, **Copy shareable
  link** mints a one-click URL that unlocks that rule for whoever opens
  it — no password prompt. Good for emailing a partner or a client a
  single link instead of a shared password. The link is a signed, expiring
  token (7 days by default); clicking it still re-resolves the rule live, so
  **disabling or deleting the rule, changing its password, or revoking access
  invalidates every link minted from it immediately** (see revocation below).
  A rule matched by an exact URI (no `*` glob) links straight
  to that page; a section/entry-type or globbed rule unlocks everything it
  covers and lands on the site root.

  > Note on revocation: every unlock — a magic link, an already-unlocked
  > session, or a remember-me cookie — carries a *revocation epoch*. **Changing
  > a rule’s password bumps that epoch, which instantly locks out everyone
  > already holding a link, a session, or a remember-me cookie**; they must
  > re-enter the new password. To cut everyone off *without* changing the
  > password, use **Revoke all access now** on the rule’s edit screen (it bumps
  > the epoch on its own). Disabling or deleting the rule still works too.
  > (Rotating the site’s `securityKey` remains the blunt instrument that also
  > invalidates every other signed Sesame token.) The same epoch applies to
  > per-entry (Protect field) passwords: changing or clearing one locks out
  > anyone who unlocked with the old one.
- **Access log.** Every unlock, failed password, and throttle event on a
  protected page is recorded — event, the rule or entry involved, IP,
  user agent, when — visible at **Sesame → Access Log** (its own
  `sesame:viewLog` permission; Lite doesn’t have the nav item at all). Rows
  older than **Settings → Advanced → Access log retention** (30 days by
  default) are purged automatically during Craft’s garbage collection; 0 or
  less keeps everything.
- **Remember-me.** Turn “Remember me” on for a rule (its edit screen), set
  **Settings → General → Remember-me duration** to something other than 0,
  and the password screen for that rule offers visitors a “Remember me on
  this device” checkbox. Checking it sets a signed cookie
  (`httpOnly`, `secure`, `sameSite=Lax`) carrying the scope key, the
  revocation epoch, and an expiry — no server-side session table to manage.
  It is not *individually* revocable, but a password change or **Revoke all
  access now** bumps the epoch and invalidates it along with every other
  outstanding unlock for that rule; rotating the site’s `securityKey` remains
  the escape hatch that kills every signed Sesame token at once.

## Template usage / overriding the password screen

The default screen lives at `src/templates/gate/challenge.twig` and needs
no setup — it renders automatically for any protected, locked page. To
restyle it:

- **Site-wide:** copy it to `sesame/gate/challenge.twig` in your own site’s
  templates folder. Site template roots win over the plugin’s, so your copy
  is used everywhere without touching the plugin.
- **Per rule:** set that rule’s **Template override** to any site template
  path (e.g. `sesame/gate/challenge-partners`). Only pages matched by that
  rule use it; everything else keeps the default (or your site-wide
  override, if you’ve also made one).

Either way, keep the form’s contract intact: `method="post"`, its
`action`, the CSRF input, the hidden scope token (`t`), the hidden
`return`, `redirectInput(return)`, and a
`type="password" name="password" id="sesame-password"` input. The template
receives `message`, `token`, `return`, `error` (string or null),
`cooldown` (bool), and `showRememberMe` (bool — Pro only; true when the
matched rule opted into remember-me). If your override wants to offer
remember-me too, add a `name="remember" value="1"` checkbox inside the form
when `showRememberMe` is true — `GateController::actionUnlock()` reads it
regardless of which template rendered the form.

## What it does and doesn’t gate

Sesame guarantees one thing completely: **the HTML page view.** A visitor
who requests a protected entry’s normal front-end URL, without a valid
session unlock, gets the password screen instead of the page — every time,
with no template code required to make that true.

**Previewing:** an editor **who can view the entry** (the section’s
`viewEntries` permission, or authorship) sees the real page in Live Preview or
the Preview button, not the password screen — they’re explicitly previewing
their own content. Everyone else is gated: an **anonymous** visitor (including
someone opening a Craft “Share” preview link), a logged-in user **without**
view access to that entry, and an editor simply browsing the live front end
with no preview request. Only a genuine preview by someone entitled to see the
entry is exempt.

It does **not** automatically gate:

- **GraphQL** and the **Element API** — both query elements directly and
  bypass Craft’s route resolution entirely, so Sesame’s hook never runs
  against them. A protected entry’s fields are still queryable unless your
  schema/query excludes it yourself.
- **Sitemaps and feeds** — anything built by iterating elements
  server-side (a sitemap plugin, an RSS template, a custom feed) will
  happily include a protected entry’s title, summary, or URL unless you
  filter it out.
- **SEO plugin metadata** — in testing, SEOmatic’s `<title>` tag and
  JSON-LD structured data rendered from the *real* entry, not the challenge
  screen, because SEOmatic injects its meta containers at
  `View::EVENT_END_PAGE` (after the page body is done rendering) using
  whatever it resolved earlier from the matched route, not from what
  actually got rendered. **Sesame now mitigates the common case on both
  editions** (`GateController::disableSeomaticRender()`): if SEOmatic is
  installed, its render is disabled for the one request serving the
  challenge screen, so that request’s title/JSON-LD comes from the
  challenge template, not the protected entry. This is scoped to a page’s
  *own* challenge response — sitemaps, feeds, GraphQL, and anything else
  reading SEOmatic data outside a normal front-end request are unaffected
  and still leak per the next bullet.
- **Anything else that reads element data outside a normal front-end
  request** — relation fields on other entries, search indexes, cached
  partials that embed a protected entry’s content.

None of this is a bug to report: it is the boundary of what a route-level
gate can promise, and every comparable Craft plugin has the same edges,
admitted or not. Keep protected content out of sitemaps, feeds, and public
GraphQL schemas through your own template and schema choices. Sealing those
paths automatically (a placeholder in listings, search, and relations, and
GraphQL-aware query filtering) is on the Pro roadmap, behind the three
features listed above.

## Static caching

A protected URL must never be served from a static full-page cache: a cached
challenge would ship a stale CSRF token, and a cached *unlocked* page would be
handed to everyone with no password. Sesame handles this on both editions.

- Every protected page view — locked **or** unlocked — is sent with no-cache
  headers, which covers the browser and reverse proxies.

- **Blitz** decides cacheability without reading response headers, so Sesame
  integrates with it directly, vetoing Blitz’s `EVENT_IS_CACHEABLE_REQUEST` for
  any protected URL. That one check gates both *writing* a cache entry and
  *serving* one (Blitz consults it at `Application::EVENT_INIT`, before Sesame’s
  gate runs), so a protected page is never cached and a page cached *before* it
  became protected stops being served. The integration is guarded — Sesame has
  no dependency on Blitz and does nothing if it isn’t installed. A rule change
  also clears the Blitz cache as a backstop.

> **Blitz server-rewrite / reverse-proxy delivery.** When Blitz serves cache
> files straight from the web server (nginx/Apache rewrites, or a CDN), PHP
> never runs on a cache hit, so no plugin — Sesame included — can intercept it.
> In that mode, also add your protected URI patterns to Blitz’s
> `excludedUriPatterns`, so those URLs are never written to the file cache in
> the first place.

Other full-page caches — Craft’s own `{% cache %}` tags, `httpcache`, a CDN —
are the site builder’s responsibility: don’t wrap a protected entry’s template
in a cache shared across visitors.

## Development

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for architecture and the local
dev loop.
