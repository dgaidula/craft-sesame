# Sesame

**Say the word.** Password-protect any Craft page — no template code, no developer.

A Craft 5 plugin by Danniel T. Gaidula.

## Requirements

**Craft CMS 5.0+ · PHP 8.2+.** Sesame is a Craft 5 plugin and does not run
on Craft 4.

## What it does

1. **CP-managed rules** — protect a page by pattern from the control panel:
   a URI (with `*` globbing), a whole section, or a whole entry type. No
   field layout to touch, no template edit — the hero feature and the
   fastest way to gate a "semi-hidden" page.
2. **Per-entry protection** — a *Sesame Protection* field an editor can add
   to any entry type, for one-off pages that need their own password
   instead of sharing a rule's.
3. **Gates the real page** — hooks Craft's own routing
   (`Element::EVENT_SET_ROUTE`), so a locked entry never reaches its normal
   template until the visitor unlocks it. There is no separate "protected
   copy" to keep in sync.
4. **Stores the secret encrypted-at-rest by default** (reversible, so an
   admin can reveal and re-share a page code later) — or bcrypt-hashed
   (write-only) if you turn that on in settings.
5. **Throttles guesses** — failed attempts are rate-limited per IP per
   protected page before the password is even compared.
6. **Ships a real password screen** — a small, deliberate design, themeable
   light/dark, fully overridable per site or per rule.

## Editions

Sesame ships two editions.

| | Lite (free) | Pro |
| --- | :---: | :---: |
| Per-entry protect field (Speakeasy parity) | ✅ | ✅ |
| **CP rules — URI glob + section/type** | ✅ | ✅ |
| Default password screen (overridable) | ✅ | ✅ |
| Encrypt-at-rest / optional bcrypt | ✅ | ✅ |
| Session unlock | ✅ | ✅ |
| Brute-force cache throttle | ✅ | ✅ |
| **SEOmatic title/JSON-LD leak mitigation** on the challenge screen | ✅ | ✅ |
| **Shareable one-click magic links**<sup>PRO</sup> (email a district a link, from the Rules edit screen) | — | ✅ |
| **Access log**<sup>PRO</sup> (unlock/fail/throttle audit trail + CP index, retention-purged) | — | ✅ |
| **Remember-me**<sup>PRO</sup> persistent signed cookie, per-rule opt-in | — | ✅ |
| Multiple named codes per rule<sup>PRO, roadmap</sup> (per-code label/expiry/revoke) | — | — |
| Scheduled<sup>PRO, roadmap</sup> auto lock/unlock (by date) | — | — |
| Leak-sealing<sup>PRO, roadmap</sup> — 🔒 placeholder in listings/search/relations, no template edits | — | — |
| GraphQL-aware gating<sup>PRO, roadmap</sup> + query-filter helpers | — | — |
| reCAPTCHA v3<sup>PRO, roadmap</sup> on the unlock form | — | — |
| CP-editable screen branding<sup>PRO, roadmap</sup> (logo/intro/colors) | — | — |
| Bulk "protect selected" element action<sup>PRO, roadmap</sup> | — | — |

Three Pro features are built and live: shareable magic links, the access
log, and remember-me (see [Pro features](#pro-features) below). Everything
marked *roadmap* above is stubbed — an `isPro()` boundary and a `// TODO
Pro:` comment at the seam it'll slot into, no working behavior yet. Lite
fully solves the "gate one page, no developer" need on its own; Pro is for
operating it at scale — one-click access for people who shouldn't need a
password, an audit trail of who got in and when, and unlocks that survive
a browser restart.

### Rename it in the control panel

Sesame can present under any name you like — the sidebar nav, the Plugins
screen, and the settings breadcrumb all follow **Settings → General →
Plugin name** (blank = "Sesame"). Set it in `config/sesame.php` so it holds
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

- **Sesame → Rules** (CP section, plugin-owned content table — editable on
  production even with `allowAdminChanges` off). Each rule matches a **URI**
  (an exact path, or a `*` glob like `district-resources/*`), a **section**
  handle, or an **entry-type** handle, and carries its own password, an
  optional custom prompt message, and an optional template override. This
  is the zero-field-layout way to gate a page, and the one to reach for
  first — it needs no changes to the entries themselves.
- **Sesame Protection field** — add the field to an entry type's layout,
  then flip it on per entry with its own password. Useful when one specific
  entry needs a password independent of any rule (or a different one than
  the rule that would otherwise match it — the field always wins). The
  password is never stored in the entry's content column; only the
  on/off flag is, so it never appears in element exports, revisions, or
  GraphQL.

A rule's password can be viewed again later from its edit screen (**Reveal
current password**) as long as it's stored in the default encrypt-at-rest
mode — turn on **Settings → General → Hash passwords** to store bcrypt
instead, which trades that recall away for write-only storage.

## Pro features

Three Pro features are fully built (everything else Pro-tagged above is a
marked stub — `isPro()` boundary + `// TODO Pro:` comment, no behavior yet).

- **Shareable magic links.** On a saved rule's edit screen, **Copy shareable
  link** mints a one-click URL that unlocks that rule for whoever opens
  it — no password prompt. Good for emailing a district or a partner a
  single link instead of a shared password. The link is a signed, expiring
  token (7 days by default); clicking it still re-resolves the rule live, so
  **disabling or deleting the rule, changing its password, or revoking access
  invalidates every link minted from it immediately** (see revocation below).
  A rule matched by an exact URI (no `*` glob) links straight
  to that page; a section/entry-type or globbed rule unlocks everything it
  covers and lands on the site root.

  > Note on revocation: every unlock — a magic link, an already-unlocked
  > session, or a remember-me cookie — carries a *revocation epoch*. **Changing
  > a rule's password bumps that epoch, which instantly locks out everyone
  > already holding a link, a session, or a remember-me cookie**; they must
  > re-enter the new password. To cut everyone off *without* changing the
  > password, use **Revoke all access now** on the rule's edit screen (it bumps
  > the epoch on its own). Disabling or deleting the rule still works too.
  > (Rotating the site's `securityKey` remains the blunt instrument that also
  > invalidates every other signed Sesame token.) The same epoch applies to
  > per-entry (Protect field) passwords: changing or clearing one locks out
  > anyone who unlocked with the old one.
- **Access log.** Every unlock, failed password, and throttle event on a
  protected page is recorded — event, the rule or entry involved, IP,
  user agent, when — visible at **Sesame → Access Log** (its own
  `sesame:viewLog` permission; Lite doesn't have the nav item at all). Rows
  older than **Settings → Advanced → Access log retention** (30 days by
  default) are purged automatically during Craft's garbage collection; 0 or
  less keeps everything.
- **Remember-me.** Turn "Remember me" on for a rule (its edit screen), set
  **Settings → General → Remember-me duration** to something other than 0,
  and the password screen for that rule offers visitors a "Remember me on
  this device" checkbox. Checking it sets a signed cookie
  (`httpOnly`, `secure`, `sameSite=Lax`) carrying the scope key, the
  revocation epoch, and an expiry — no server-side session table to manage.
  It is not *individually* revocable, but a password change or **Revoke all
  access now** bumps the epoch and invalidates it along with every other
  outstanding unlock for that rule; rotating the site's `securityKey` remains
  the escape hatch that kills every signed Sesame token at once.

## Template usage / overriding the password screen

The default screen lives at `src/templates/gate/challenge.twig` and needs
no setup — it renders automatically for any protected, locked page. To
restyle it:

- **Site-wide:** copy it to `sesame/gate/challenge.twig` in your own site's
  templates folder. Site template roots win over the plugin's, so your copy
  is used everywhere without touching the plugin.
- **Per rule:** set that rule's **Template override** to any site template
  path (e.g. `sesame/gate/challenge-district`). Only pages matched by that
  rule use it; everything else keeps the default (or your site-wide
  override, if you've also made one).

Either way, keep the form's contract intact: `method="post"`, its
`action`, the CSRF input, the hidden scope token (`t`), the hidden
`return`, `redirectInput(return)`, and a
`type="password" name="password" id="sesame-password"` input. The template
receives `message`, `token`, `return`, `error` (string or null),
`cooldown` (bool), and `showRememberMe` (bool — Pro only; true when the
matched rule opted into remember-me). If your override wants to offer
remember-me too, add a `name="remember" value="1"` checkbox inside the form
when `showRememberMe` is true — `GateController::actionUnlock()` reads it
regardless of which template rendered the form.

## What it does and doesn't gate

Sesame guarantees one thing completely: **the HTML page view.** A visitor
who requests a protected entry's normal front-end URL, without a valid
session unlock, gets the password screen instead of the page — every time,
with no template code required to make that true.

It does **not** automatically gate:

- **GraphQL** and the **Element API** — both query elements directly and
  bypass Craft's route resolution entirely, so Sesame's hook never runs
  against them. A protected entry's fields are still queryable unless your
  schema/query excludes it yourself.
- **Sitemaps and feeds** — anything built by iterating elements
  server-side (a sitemap plugin, an RSS template, a custom feed) will
  happily include a protected entry's title, summary, or URL unless you
  filter it out.
- **SEO plugin metadata** — in testing, SEOmatic's `<title>` tag and
  JSON-LD structured data rendered from the *real* entry, not the challenge
  screen, because SEOmatic injects its meta containers at
  `View::EVENT_END_PAGE` (after the page body is done rendering) using
  whatever it resolved earlier from the matched route, not from what
  actually got rendered. **Sesame now mitigates the common case on both
  editions** (`GateController::disableSeomaticRender()`): if SEOmatic is
  installed, its render is disabled for the one request serving the
  challenge screen, so that request's title/JSON-LD comes from the
  challenge template, not the protected entry. This is scoped to a page's
  *own* challenge response — sitemaps, feeds, GraphQL, and anything else
  reading SEOmatic data outside a normal front-end request are unaffected
  and still leak per the next bullet.
- **Anything else that reads element data outside a normal front-end
  request** — relation fields on other entries, search indexes, cached
  partials that embed a protected entry's content.

None of this is a bug to report — it's the boundary of what a route-level
gate can promise, and every comparable Craft plugin (including the free
incumbent this one competes with) has the same edges, admitted or not.
**Sealing these paths — a 🔒 placeholder in listings/search/relations with
no template edits, and GraphQL-aware query filtering — is exactly what
Sesame Pro is for.** On Lite, treat leak prevention outside the page view
as the site builder's responsibility: keep protected content out of
sitemaps, feeds, and public GraphQL schemas by your own template/schema
choices.

## Static caching

A protected URL must never be served from a static full-page cache: a cached
challenge would ship a stale CSRF token, and a cached *unlocked* page would be
handed to everyone with no password. Sesame handles this on both editions.

- Every protected page view — locked **or** unlocked — is sent with no-cache
  headers, which covers the browser and reverse proxies.

- **Blitz** decides cacheability without reading response headers, so Sesame
  integrates with it directly, vetoing Blitz's `EVENT_IS_CACHEABLE_REQUEST` for
  any protected URL. That one check gates both *writing* a cache entry and
  *serving* one (Blitz consults it at `Application::EVENT_INIT`, before Sesame's
  gate runs), so a protected page is never cached and a page cached *before* it
  became protected stops being served. The integration is guarded — Sesame has
  no dependency on Blitz and does nothing if it isn't installed. A rule change
  also clears the Blitz cache as a backstop.

> **Blitz server-rewrite / reverse-proxy delivery.** When Blitz serves cache
> files straight from the web server (nginx/Apache rewrites, or a CDN), PHP
> never runs on a cache hit, so no plugin — Sesame included — can intercept it.
> In that mode, also add your protected URI patterns to Blitz's
> `excludedUriPatterns`, so those URLs are never written to the file cache in
> the first place.

Other full-page caches — Craft's own `{% cache %}` tags, `httpcache`, a CDN —
are the site builder's responsibility: don't wrap a protected entry's template
in a cache shared across visitors.

## Development

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for architecture and the local
dev loop.
