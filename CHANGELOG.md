# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.0.0 - unreleased

### Added
- Initial release.
- CP-managed rules (**Sesame → Rules**) protecting a URI glob, a section, or
  an entry type behind a shared password — no field layout or template
  changes required.
- Per-entry *Sesame Protection* field for one-off password gating on a
  single entry.
- Front-end gate via `Element::EVENT_SET_ROUTE`: a locked, unprotected
  visitor is routed to a password challenge in place of the entry's normal
  template; a correct password unlocks the page for the session.
- A designed, self-contained, theme-aware default password screen
  (`gate/challenge.twig`), overridable per site or per rule.
- Encrypt-at-rest password storage by default (admin-recallable), with an
  optional bcrypt (write-only) mode.
- Per-IP, per-page brute-force throttling on the unlock endpoint.
- Editions framework (Lite/Pro) with Pro gates stubbed behind
  `Plugin::isPro()`.
- SEOmatic title/JSON-LD leak mitigation on the challenge screen
  (`GateController::disableSeomaticRender()`) — Lite and Pro both.
- **Pro: shareable magic links.** `Gate::signMagicLink()`/`readMagicLink()`,
  `GateController::actionLink`, and a "Copy shareable link" action on the
  Rules edit screen mint a one-click, expiring, permission-gated unlock URL
  for a rule — the scope is still re-resolved live on click, so an edited
  or disabled rule takes effect immediately.
- **Pro: access log.** `services/AccessLog` records unlock/fail/throttle
  events to `{{%sesame_access_log}}` (guarded by `isPro()`; the table
  itself ships on every edition via the install migration), a new
  **Sesame → Access Log** CP screen (`sesame:viewLog` permission) lists
  recent events, and a `Gc::EVENT_RUN` handler purges rows past
  `Settings::$accessLogRetentionDays` (default 30).
- **Pro: remember-me.** A per-rule `rememberMe` opt-in plus
  `Settings::$rememberMeDuration` let a visitor check "Remember me" on the
  password screen; `Gate::unlock()` then sets a signed cookie
  (`httpOnly`/`secure`/`sameSite=Lax`, exp inside the signed value) that
  `Gate::isUnlocked()` also honors, so the unlock survives session expiry.
- Marked-stub seams (`// TODO Pro:`) for the remaining Pro roadmap:
  multiple named codes per rule, scheduled lock/unlock, GraphQL-aware
  gating, reCAPTCHA v3, CP-editable screen branding, and a bulk "protect
  selected" element action.
