# Sesame — Development Guide

This guide is for developers who want to customize Sesame, build an
integration against it, or contribute to it. For install-and-use documentation,
start with the [README](../README.md).

## Table of contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [The editions model](#the-editions-model)
4. [Data model and storage](#data-model-and-storage)
5. [The gate spine](#the-gate-spine)
6. [Password storage](#password-storage)
7. [The CP Rules UI](#the-cp-rules-ui)
8. [Developing against a host site](#developing-against-a-host-site)
9. [Coding standards and layout](#coding-standards-and-layout)

---

## Overview

> **Requirements:** Craft CMS 5.0+ and PHP 8.2+. Sesame is a Craft 5
> plugin and does not run on Craft 4.

Sesame password-protects Craft entries with no template code. An editor
defines a **rule** (a URI glob, a section handle, or an entry-type handle)
in the CP, or flips on the **Sesame Protection** field on a single entry;
either way, Craft's own request routing is intercepted before the entry's
normal template renders, and an unauthenticated visitor sees a password
screen instead.

## Architecture

### The `Plugin` bootstrap

`src/Plugin.php` is the whole wiring diagram. `Plugin::config()` registers
the plugin's service components (`rules`, `secrets`, `gate`, `throttle`).
`Plugin::init()` then registers, via Yii events:

- **Twig variable** — `craft.sesame` → `web\twig\PluginVariable`.
- **Template roots** — `src/templates/` is registered as the `sesame` root
  for both CP and site template modes (site template roots still win, which
  is what makes `sesame/gate/challenge.twig` overridable from a site's own
  templates).
- **Field type** — registers `fields/Protect`.
- **Site URL rule** — `POST sesame/gate/unlock` → `GateController::actionUnlock`.
- **CP URL rules** — `sesame/rules`, `sesame/rules/new`,
  `sesame/rules/<uid>` → `RulesController`.
- **Permissions** — `sesame:manageRules` (constant
  `Plugin::PERMISSION_MANAGE_RULES`), required by every `RulesController`
  action.
- **The request gate itself** — see [The gate spine](#the-gate-spine).

`init()` also applies the optional **plugin rename**:
`Settings::$pluginName` relabels the plugin everywhere in the CP (sidebar,
Plugins screen, settings breadcrumb). Blank keeps "Sesame".

### The pieces, walked

- **`models/Settings.php`** — global settings, stored in **project
  config**: `pluginName`, `hashPasswords`, `attemptLimit`/`attemptWindow`.
  Dev-owned, locked on production when `allowAdminChanges` is off.
- **`models/Rule.php`** — one row of `{{%sesame_rules}}`. Carries the
  *already-encoded* secret (never plaintext) — the CP save action is
  responsible for calling `Secrets::store()` on a posted password before
  building/saving a `Rule`.
- **`models/Scope.php`** — a resolved protection target (from a matched
  rule, or an entry's own Protect field). Never persisted or round-tripped
  to the client; the challenge form only ever carries a signed
  `{type, uid}` token, and the real secret is re-read from the DB on every
  request.
- **`models/ProtectValue.php`** — the Protect field's normalized value.
  `password` is transient: populated only right after a POST with a new
  password, never re-hydrated from a saved entry.
- **`services/Rules.php`** — CRUD for `{{%sesame_rules}}` plus
  `match(Entry): ?Scope`, which evaluates enabled rules in `sortOrder` and
  returns the first match.
- **`services/Secrets.php`** — the generic `store()`/`verify()`/`reveal()`
  contract (shared by rules and per-entry passwords), plus CRUD for
  `{{%sesame_entry_secrets}}`, keyed by the owning element's **UID**.
- **`services/Gate.php`** — decides whether an entry is protected
  (`isProtected()`, checking the Protect field before falling back to
  `Rules::match()`), whether the current session has already unlocked it
  (`isUnlocked()`), and issues/validates the signed scope token
  (`signScopeToken()`/`readScopeToken()`/`scopeFromToken()`).
- **`services/Throttle.php`** — a cache-backed attempt counter per
  `ip:scopeKey`, checked before `Secrets::verify()` so a flood can't spend
  bcrypt CPU either.
- **`controllers/GateController.php`** (site, `allowAnonymous`) —
  `actionChallenge` renders the password screen; `actionUnlock` verifies
  the POST, unlocking the session on success.
- **`controllers/RulesController.php`** (CP) — the Rules list/edit/save/
  delete/reorder/reveal-password screens. Requires
  `Plugin::PERMISSION_MANAGE_RULES`.
- **`fields/Protect.php`** — the per-entry field type. Stores only
  `{enabled: bool}` in the content column; `afterElementSave()` persists
  (or clears) the real secret into `{{%sesame_entry_secrets}}`, keyed by
  the element's UID, once it's guaranteed to exist.
- **`web/twig/PluginVariable.php`** — exposes `craft.sesame.*`.

## The editions model

```php
public const EDITION_LITE = 'lite';
public const EDITION_PRO  = 'pro';

/** Single place the Pro gates ask. */
public function isPro(): bool
{
    return $this->is(self::EDITION_PRO);
}
```

Every Pro gate funnels through `Plugin::isPro()`, server-side, at the
narrowest choke point. Lite currently ignores the Pro-only columns already
present in `{{%sesame_rules}}` (`codesJson`, `unlockUntil`, `rememberMe`)
and never writes to `{{%sesame_access_log}}` — both tables/columns exist
from `Install` so a future Pro build needs no destructive schema change,
only new reads/writes gated behind `isPro()`.

## Data model and storage

### `{{%sesame_rules}}`

One row per rule: `enabled`, `sortOrder`, `label`, `matchType`
(`uri`/`section`/`entryType`), `pattern`, `secret`/`secretMode`, optional
`message`/`templateOverride`, plus the Pro-only `codesJson`/`unlockUntil`/
`rememberMe` columns. Content — editable on production regardless of
`allowAdminChanges`, exactly like `Rules::save()`/`delete()`/`reorder()`
write it.

### `{{%sesame_entry_secrets}}`

One row per protected entry, keyed by `elementUid` (unique index) — never
by element ID, since IDs aren't portable across environments. Written by
`Secrets::storeForEntry()`/read by `Secrets::getForEntry()`.

### `{{%sesame_access_log}}`

Pro's per-unlock audit trail. Schema created by `Install` now; Lite never
writes to it.

## The gate spine

Registered in `Plugin::registerRequestGate()`, on `craft\base\Element::EVENT_SET_ROUTE`,
scoped to `Entry::class`:

```php
Event::on(Entry::class, Element::EVENT_SET_ROUTE, function (SetElementRouteEvent $event) {
    $scope = $this->gate->isProtected($event->sender);
    if ($scope === null || $this->gate->isUnlocked($scope)) {
        return;
    }
    $event->route = ['sesame/gate/challenge', [
        't' => $this->gate->signScopeToken($scope),
        'return' => $entry->url ?: $entry->uri,
    ]];
    $event->handled = true;
});
```

Setting `route` + `handled` replaces the entry's normal `templates/render`
route with `GateController::actionChallenge` — no redirect, so the URL bar
stays on the real page and the response is a plain 200. The signed token
carries only `{type, uid}` (never the secret) via
`Security::hashData()`/`validateData()`; `GateController` and
`Gate::scopeFromToken()` always re-resolve the *live* secret from the DB,
so an edited password or a disabled rule takes effect immediately, even for
an already-issued token.

The unlock POST (`GateController::actionUnlock`) checks
`Throttle::tooMany()` **before** `Secrets::verify()`, sets the session flag
on success (`Gate::unlock()`), and redirects back to the original URL via
Craft's `redirectToPostedUrl()` (the hashed `redirect` field, not the raw
`return` param, is what's actually trusted).

## Password storage

`Secrets::store()` picks the encoding based on `Settings::$hashPasswords`:

- **Default (`false`): encrypt-at-rest** — `Security::encryptByKey()` /
  `decryptByKey()` + `hash_equals()` on verify. Reversible, so a rule's
  password can be revealed again in the CP (`RulesController::actionRevealPassword`)
  for an admin who needs to re-share a shared page code.
- **`true`: bcrypt** — `Security::hashPassword()`/`validatePassword()`.
  Write-only; `Secrets::reveal()` always returns `null` for a hash-mode
  secret, and the Rules edit screen shows "written-only" instead of a
  reveal affordance.

Both modes fail closed on a malformed/foreign stored value rather than
throwing.

## The CP Rules UI

`RulesController` + `templates/rules/index.twig` + `templates/rules/_edit.twig`:

- **`actionIndex`** — the rule list (label, target summary, enabled
  status), with inline up/down reorder and delete forms.
- **`actionEdit`** — new (`?uid` omitted) or existing (`?uid=`) rule form.
  Supplies section/entry-type handle options for the `section`/`entryType`
  match-type dropdowns (`Craft::$app->getEntries()->getAllSections()`/
  `getAllEntryTypes()`).
- **`actionSave`** — hydrates a `Rule` from the post, calls
  `Secrets::store()` on a non-blank posted password (setting
  `secret`/`secretMode`), and — the key behavior — **leaves the existing
  rule's `secret`/`secretMode` untouched when the password field is left
  blank on an edit**. A blank password on a *new* rule is a validation
  error (a rule with no password can never be satisfied). Re-renders with
  errors on failure.
- **`actionDelete`** / **`actionReorder`** — straightforward calls into
  `Rules::delete()`/`Rules::reorder()`.
- **`actionRevealPassword`** — POST, JSON, permission-gated; returns
  `Secrets::reveal()` for one rule, used by the edit screen's "Reveal
  current password" button (encrypt-mode only).

The `_edit.twig` matchType select toggles between three pattern inputs
(URI/section/entryType); a small inline script keeps exactly one of them
named `pattern` at submit time, tracking the select's *live* value rather
than the page's load-time state, so switching match type without a reload
still posts the right field.

## Developing against a host site

The plugin repo contains no Craft installation — you develop it inside any
Craft 5 host site (a DDEV project works well). Point the host site's Composer
at your local clone with a **path repository**, which symlinks the plugin so
edits are live immediately:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../craft-sesame",
      "options": { "symlink": true }
    }
  ]
}
```

Then, from the host site:

```bash
composer require dgaidula/craft-sesame:@dev
php craft plugin/install sesame
php craft up            # applies any pending plugin migrations
```

To try the Pro edition locally:

```bash
php craft project-config/set plugins.sesame.edition pro
```

**When you change the schema:** bump `Plugin::$schemaVersion`, add a migration
under `src/migrations/` (`php craft migrate/create <name> --plugin=sesame`
scaffolds one), mirror the change in `Install.php` for fresh installs, and keep
the migration idempotent.

## Coding standards and layout

- **PHP** ≥ 8.2, **Craft** ^5.0 — the only runtime requirements.
- **PSR-4** — everything under `src/` maps to the `dgaidula\sesame\`
  namespace.
- **Code style** — `php-cs-fixer` (via the dev-only shim), configured in
  `.php-cs-fixer.dist.php`:

```bash
composer cs-check   # dry-run with a diff
composer cs-fix     # apply
```

- **House rules worth knowing before a PR:**
  - Pro gates go through `Plugin::isPro()`, server-side, at the narrowest
    choke point.
  - Never key a lookup by element/rule ID — always the UID. IDs are
    per-environment auto-increments; UIDs travel with project config and
    content.
  - A `Scope`'s secret is always re-read from the DB when a token is
    resolved — never trust a secret (or its hash) carried on the wire.

### `src/` directory map

```
src/
├── Plugin.php                         # Bootstrap: components, events, routes, permissions
├── icon.svg
├── controllers/
│   ├── GateController.php             # Site: password challenge + unlock
│   └── RulesController.php            # CP: Rules list/edit/save/delete/reorder/reveal
├── fields/
│   └── Protect.php                    # Sesame Protection field
├── migrations/
│   └── Install.php                    # sesame_rules / sesame_entry_secrets / sesame_access_log
├── models/
│   ├── ProtectValue.php               # Protect field's normalized value
│   ├── Rule.php                       # One sesame_rules row
│   ├── Scope.php                      # A resolved protection target
│   └── Settings.php                   # Global settings (project config)
├── services/
│   ├── Gate.php                       # isProtected/isUnlocked/unlock/token sign+read
│   ├── Rules.php                      # sesame_rules CRUD + match()
│   ├── Secrets.php                    # store/verify/reveal + per-entry secret CRUD
│   └── Throttle.php                   # Per-IP/scope brute-force cache counter
├── templates/
│   ├── _field/protect-input.twig      # CP: Protect field input
│   ├── gate/challenge.twig            # Site: the default password screen
│   ├── rules/index.twig               # CP: Rules list
│   ├── rules/_edit.twig               # CP: Rule edit form
│   └── settings.twig                  # CP: tabbed settings
└── web/
    └── twig/PluginVariable.php        # craft.sesame.*
```
