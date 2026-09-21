<?php

namespace iceboxind\sesame\models;

use craft\base\Model;

/**
 * A resolved protection target — the outcome of matching a rule, or an
 * entry's own Protect field, against the current request.
 *
 * Never persisted and never round-tripped through the client: the challenge
 * form only ever sees a signed token that carries {@see self::$type} +
 * {@see self::$uid}; the secret is re-read from the DB on every request (see
 * {@see \iceboxind\sesame\services\Gate::scopeFromToken()}), so an edited
 * password or a disabled rule takes effect immediately.
 */
class Scope extends Model
{
    /** @var 'rule'|'entry' */
    public string $type = 'rule';

    /** The owning rule's uid, or the protected element's uid. */
    public string $uid = '';

    /**
     * Stable per-scope session/throttle key (`rule.<uid>` / `entry.<uid>`).
     * Lazily computed and cached by {@see \iceboxind\sesame\services\Gate::scopeKey()}.
     */
    public ?string $scopeKey = null;

    public ?string $message = null;
    public ?string $templateOverride = null;

    /** The stored (encrypted or bcrypt-hashed) secret — never plaintext. */
    public string $secret = '';

    /** @var 'encrypt'|'hash' */
    public string $secretMode = 'encrypt';

    /**
     * Revocation epoch (both editions). The current, live value from the
     * backing rule / entry-secret row. An unlock stores this into the session
     * (and into any remember-me cookie / magic link); {@see \iceboxind\sesame\services\Gate::isUnlocked()}
     * re-reads the live value and compares, so a bumped epoch (password change
     * or explicit revoke) instantly invalidates every outstanding unlock.
     */
    public int $epoch = 0;

    /** PRO. Whether this scope's rule opted into remember-me. Always false for a per-entry (Protect field) scope — that opt-in doesn't exist there yet. */
    public bool $rememberMe = false;
}
