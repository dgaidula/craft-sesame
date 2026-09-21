# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.0.0 - 2026-09-21

First public release. Password-protect Craft pages with no template code —
managed entirely in the control panel and editable on production.

### Both editions

- **CP-managed rules** (**Sesame → Rules**) protecting a page by exact URL —
  no field-layout or template changes required. Enforcement is never
  edition-gated: a rule authored on Pro keeps protecting after a downgrade.
- **Per-entry *Sesame Protection* field** for one-off gating of a single entry,
  editable on production.
- **Front-end gate** via `Element::EVENT_SET_ROUTE`: a locked visitor is routed
  to a password challenge in place of the entry's normal template; a correct
  password unlocks the page for the session. Open-redirect-safe returns.
- **Designed challenge screen** — self-contained (no external assets, CSP-safe),
  theme-aware, overridable per site or per rule.
- **Password storage** encrypt-at-rest (admin-recallable) by default, or bcrypt
  (write-only) mode.
- **Revocation** — changing a rule's password, or "Revoke all access", bumps a
  per-rule epoch that instantly cuts off every outstanding session, remember-me
  cookie, and magic link. The per-entry field carries the same epoch.
- **Brute-force throttling** on the unlock endpoint — a per-IP bucket plus an
  IP-independent per-scope ceiling (defeating rotating `X-Forwarded-For`),
  checked before the password compare, in a fixed window.
- **Static-cache safety** — no-cache headers on every gate response, a Blitz
  cache veto and purge-on-change, so a protected page is never served from a
  static cache without the password.
- **SEOmatic leak mitigation** — the challenge screen never emits the protected
  entry's title or JSON-LD.

### Pro

- **Pattern rules** — protect a URI glob, a whole section, or a whole entry
  type (authoring; Lite protects exact URLs).
- **Multiple named codes per rule** — each with its own label, optional expiry,
  individual revoke, and shareable link; recorded on every access-log row.
  Revoking or expiring one code cuts off just its holders.
- **Shareable magic links** — a one-click, expiring, per-code unlock URL, with
  the scope re-resolved live on click so an edited or revoked rule takes effect
  immediately.
- **Access log** (**Sesame → Access Log**) of unlock / link / fail / throttle /
  reveal events, with a configurable retention GC.
- **Remember-me** — a per-rule opt-in and a signed cookie so an unlock can
  survive the browser session.
- **Scheduled lock/unlock** — protect a rule's pages only within a time window.
- **Challenge-screen branding** — CP-editable logo, heading, intro, and accent
  colour; a site default plus per-rule overrides, editable on production.
