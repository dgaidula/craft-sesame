<?php

namespace dgaidula\sesame\models;

use craft\base\Model;

/**
 * Normalized value of the Sesame Protection field ({@see \dgaidula\sesame\fields\Protect}).
 *
 * `password` is TRANSIENT — only populated right after a POST that included
 * a new password. It is never persisted in the content column (see
 * `serializeValue()`, which stores only `enabled`) and is never re-hydrated
 * from a saved entry, so a stored password can't leak back out through the
 * field. The real, encoded secret lives in `{{%sesame_entry_secrets}}`.
 */
class ProtectValue extends Model
{
    public bool $enabled = false;
    public ?string $password = null;
}
