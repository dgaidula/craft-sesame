<?php

namespace iceboxind\sesame\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;

/**
 * One named code of a rule (a row of {{%sesame_rule_codes}}) — a credential the
 * rule accepts, with its own label, optional expiry, and individual revoke. A
 * rule's "code one" (earliest by dateCreated) is its password; Pro adds more.
 *
 * {@see \iceboxind\sesame\services\Codes} owns all reads/writes. `secret`/
 * `secretMode` hold the ALREADY-ENCODED value (encrypt or bcrypt), exactly like
 * a rule secret used to and like an entry secret does — never plaintext.
 */
class Code extends Model
{
    public ?int $id = null;

    /** The codeId carried by sessions, remember-me cookies, and magic links. */
    public ?string $uid = null;

    /** The owning rule's uid. */
    public string $ruleUid = '';

    public ?string $label = null;

    /** The stored (encrypted or bcrypt-hashed) secret — never plaintext. */
    public string $secret = '';

    /** @var 'encrypt'|'hash' */
    public string $secretMode = 'encrypt';

    /** UTC datetime string, or null = never expires. */
    public ?string $expiresAt = null;

    /** UTC datetime string, or null = not revoked. */
    public ?string $revokedAt = null;

    /**
     * Whether this code currently grants access: not revoked, and not past its
     * expiry. FAILS CLOSED (returns false — deny) on an unparseable expiry, so a
     * bad date never silently keeps a code live. Comparison is by instant.
     */
    public function isActive(?\DateTimeInterface $now = null): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->expiresAt === null) {
            return true;
        }

        $now ??= new \DateTime('now', new \DateTimeZone('UTC'));
        $exp = DateTimeHelper::toDateTime($this->expiresAt, false, false);
        if ($exp === false) {
            return false; // unparseable expiry → deny (fail closed)
        }

        return $now < $exp;
    }
}
