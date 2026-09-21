<?php

namespace iceboxind\sesame\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use iceboxind\sesame\models\ProtectValue;
use iceboxind\sesame\Plugin;

/**
 * Per-entry password protection: a lightswitch "Protect this entry" + a
 * password input. The password itself is NEVER stored in the content
 * column — only `{enabled: bool}` is serialized there (see
 * `serializeValue()`). The real secret lives in `{{%sesame_entry_secrets}}`,
 * keyed by the owning element's UID, encoded via
 * {@see \iceboxind\sesame\services\Secrets} exactly like a rule's secret.
 */
class Protect extends Field
{
    public static function displayName(): string
    {
        return Craft::t('sesame', 'Sesame Protection');
    }

    public static function icon(): string
    {
        return 'lock';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): ProtectValue
    {
        if ($value instanceof ProtectValue) {
            return $value;
        }

        $data = is_array($value) ? $value : [];

        return new ProtectValue([
            'enabled' => (bool) ($data['enabled'] ?? false),
            // Transient — see ProtectValue::$password. Only set right after a
            // POST that included a new (non-blank) password.
            'password' => isset($data['password']) && $data['password'] !== '' ? (string) $data['password'] : null,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof ProtectValue) {
            return ['enabled' => false];
        }

        return ['enabled' => $value->enabled];
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        /** @var ProtectValue $value */
        return Craft::$app->getView()->renderTemplate('sesame/_field/protect-input', [
            'name' => $this->handle,
            'value' => $value,
            'hasSecret' => $element && $element->uid
                ? Plugin::getInstance()->secrets->hasEntrySecret($element->uid)
                : false,
        ]);
    }

    /**
     * Persists the password (if a new one was posted) or clears it (if
     * protection was switched off) into {{%sesame_entry_secrets}}, keyed by
     * the element's UID. Runs after the element itself is saved, so the UID
     * is guaranteed to exist.
     */
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        $uid = $element->uid ?? null;
        if ($uid) {
            /** @var ProtectValue $value */
            $value = $element->getFieldValue($this->handle);
            $secrets = Plugin::getInstance()->secrets;

            if (!$value->enabled) {
                // Keep the epoch alive across a later re-enable — see
                // Secrets::disableForEntry(). NOT a hard delete (that happens
                // only when the entry itself is deleted, below).
                $secrets->disableForEntry($uid);
            } elseif ($value->password !== null) {
                $secrets->storeForEntry($uid, $value->password);
            } elseif (!$secrets->hasEntrySecret($uid)) {
                // enabled, no password posted, and none stored before: the
                // editor turned protection on without a password. FAIL CLOSED —
                // record it as protected-with-no-secret so the gate challenges
                // (and nothing unlocks) rather than serving the page publicly.
                $secrets->markProtectedWithoutSecret($uid);
            }
            // enabled && password === null && a secret already exists:
            // protection stays on and the stored secret is left untouched —
            // re-saving the entry without touching the password field keeps it.
        }

        parent::afterElementSave($element, $isNew);
    }

    /**
     * When a protected entry is deleted, remove its stored secret so the
     * encrypted password doesn't linger in {{%sesame_entry_secrets}} forever
     * (the table has no cascade FK, since it is keyed by element UID rather
     * than id). Hard-delete only; a soft-deleted entry that is restored keeps
     * its protection.
     */
    public function afterElementDelete(ElementInterface $element): void
    {
        if ($element->uid && $element->hardDelete) {
            Plugin::getInstance()->secrets->clearForEntry($element->uid);
        }

        parent::afterElementDelete($element);
    }
}
