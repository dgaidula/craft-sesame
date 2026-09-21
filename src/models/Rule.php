<?php

namespace iceboxind\sesame\models;

use craft\base\Model;

/**
 * One row of {{%sesame_rules}} — an editor-defined match (a URI glob, a
 * section handle, or an entry-type handle) that protects everything it matches.
 *
 * The rule holds NO secret of its own: its password(s) are rows in
 * {{%sesame_rule_codes}}, owned by {@see \iceboxind\sesame\services\Codes}, and
 * "code one" (the earliest) is the rule's primary password. This model carries
 * only the match definition, the revocation epoch, and the Pro-only scheduling,
 * remember-me, and branding columns.
 */
class Rule extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public bool $enabled = true;
    public int $sortOrder = 0;
    public string $label = '';

    /** @var 'uri'|'section'|'entryType' */
    public string $matchType = 'uri';

    /** A URI glob (`members`, `members/*`), or a section/entry-type handle. */
    public string $pattern = '';

    // The rule's secret(s) are NOT stored here any more — they are rows in
    // {{%sesame_rule_codes}}, one per named code, owned by the Codes service.
    // "Code one" (earliest) is the rule's password. See docs/DEVELOPMENT.md.

    public ?string $message = null;
    public ?string $templateOverride = null;

    /**
     * Revocation epoch (both editions). Read from the row for {@see toScope()};
     * never written through this model — {@see \iceboxind\sesame\services\Rules::save()}
     * leaves the column untouched on a normal save and bumps it with an atomic
     * `epoch = epoch + 1` when the password changes, so a concurrent bump can't
     * be clobbered by writing back a stale absolute value.
     */
    public int $epoch = 0;

    // --- Pro columns: present in schema, Lite ignores them. ---

    /**
     * PRO. Scheduled lock/unlock (P1.1). The rule protects only within
     * [protectFrom, protectUntil). Stored as UTC datetime strings; either bound
     * nullable. Authoring is Pro-gated (RulesController); enforcement is honored
     * on every edition (a grandfathered schedule keeps working after a downgrade).
     */
    public ?string $protectFrom = null;
    public ?string $protectUntil = null;

    /** PRO. Remember-me opt-in per rule — read by {@see toScope()} and honored by {@see \iceboxind\sesame\services\Gate::unlock()} only when `Plugin::isPro()` and `Settings::$rememberMeDuration` > 0. */
    public bool $rememberMe = false;

    /**
     * PRO. Per-rule challenge-screen branding overrides (P1.3); null = inherit
     * the site default ({@see \iceboxind\sesame\services\Branding}). The per-rule
     * INTRO override is the existing {@see $message}. Authoring Pro-gated;
     * rendered on every edition.
     */
    public ?string $brandHeading = null;
    public ?string $brandAccent = null;
    public ?int $brandLogoId = null;

    public function rules(): array
    {
        return [
            [['label', 'matchType', 'pattern'], 'required'],
            [['matchType'], 'in', 'range' => ['uri', 'section', 'entryType']],
            [['enabled', 'rememberMe'], 'boolean'],
            [['sortOrder', 'epoch', 'brandLogoId'], 'integer'],
            [['label', 'pattern', 'templateOverride', 'brandHeading'], 'string', 'max' => 255],
            [['brandAccent'], 'string', 'max' => 32],
            [['message'], 'string'],
            [['protectFrom', 'protectUntil'], 'safe'],
        ];
    }

    /**
     * Whether this rule's schedule is active at $now (default: now, UTC) — i.e.
     * the rule should protect. No schedule (both bounds null) is always active.
     * FAILS CLOSED (returns true, keep protecting) on a stored bound that can't
     * be parsed, so a bad date never silently opens a page. Comparisons are by
     * instant, so the stored timezone doesn't matter.
     */
    public function isScheduledActive(?\DateTimeInterface $now = null): bool
    {
        if ($this->protectFrom === null && $this->protectUntil === null) {
            return true;
        }

        $now ??= new \DateTime('now', new \DateTimeZone('UTC'));

        if ($this->protectFrom !== null) {
            $from = \craft\helpers\DateTimeHelper::toDateTime($this->protectFrom, false, false);
            if ($from === false) {
                return true; // unparseable → fail closed (protect)
            }
            if ($now < $from) {
                return false; // before the window → not yet protecting
            }
        }

        if ($this->protectUntil !== null) {
            $until = \craft\helpers\DateTimeHelper::toDateTime($this->protectUntil, false, false);
            if ($until === false) {
                return true; // fail closed
            }
            if ($now >= $until) {
                return false; // window has ended → no longer protecting
            }
        }

        return true;
    }

    /**
     * Builds the {@see Scope} this rule protects with, for the gate to check.
     * No secret rides on a rule scope any more — the gate verifies a submitted
     * password against the rule's active codes ({@see \iceboxind\sesame\services\Codes}),
     * keyed by this scope's uid.
     */
    public function toScope(): Scope
    {
        return new Scope([
            'type' => 'rule',
            'uid' => (string) $this->uid,
            'message' => $this->message,
            'templateOverride' => $this->templateOverride,
            'epoch' => $this->epoch,
            'rememberMe' => $this->rememberMe,
        ]);
    }
}
