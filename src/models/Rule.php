<?php

namespace iceboxind\sesame\models;

use craft\base\Model;

/**
 * One row of {{%sesame_rules}} — an editor-defined match (a URI glob, a
 * section handle, or an entry-type handle) that protects everything it
 * matches with a single shared password.
 *
 * `secret`/`secretMode` hold the ALREADY-ENCODED value from
 * {@see \iceboxind\sesame\services\Secrets::store()} — this model never
 * carries a raw password; the CP save action (presentation layer) is
 * responsible for calling Secrets::store() on a posted password before
 * building/saving a Rule.
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

    /** The stored (encrypted or bcrypt-hashed) secret — never plaintext. */
    public string $secret = '';

    /** @var 'encrypt'|'hash' */
    public string $secretMode = 'encrypt';

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

    /** PRO. Multiple named codes (JSON). TODO Pro: not yet read anywhere. */
    public ?string $codesJson = null;

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

    public function rules(): array
    {
        return [
            [['label', 'matchType', 'pattern'], 'required'],
            [['matchType'], 'in', 'range' => ['uri', 'section', 'entryType']],
            [['secretMode'], 'in', 'range' => ['encrypt', 'hash']],
            [['enabled', 'rememberMe'], 'boolean'],
            [['sortOrder', 'epoch'], 'integer'],
            [['label', 'pattern', 'templateOverride'], 'string', 'max' => 255],
            [['message', 'secret', 'codesJson'], 'string'],
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

    /** Builds the {@see Scope} this rule protects with, for the gate to check. */
    public function toScope(): Scope
    {
        return new Scope([
            'type' => 'rule',
            'uid' => (string) $this->uid,
            'message' => $this->message,
            'templateOverride' => $this->templateOverride,
            'secret' => $this->secret,
            'secretMode' => $this->secretMode,
            'epoch' => $this->epoch,
            'rememberMe' => $this->rememberMe,
        ]);
    }
}
