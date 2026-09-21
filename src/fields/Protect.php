<?php

namespace iceboxind\sesame\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\ElementHelper;
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

    /**
     * Protection is a property of the ENTRY, not of one of its translations, and
     * the secret table is keyed by a single element UID shared across every site.
     * Declaring the field untranslatable keeps the `enabled` flag propagating
     * identically to all sites, so one site's save can never tombstone the shared
     * secret row while another site still expects it — closing the multi-site
     * fail-open a translatable value would open. Per-site passwords, if ever
     * wanted, would key the table by (elementUid, siteId) instead.
     */
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
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
     *
     * Draft/revision handling (the provisional-draft flow the CP edit screen
     * uses for every entry):
     *  - **Revision** — an immutable snapshot; never read, write, or tombstone a
     *    secret from one.
     *  - **Draft** — a staging copy with its OWN uid that must not change the
     *    live page before publish. Remember only a freshly TYPED password, keyed
     *    by the draft's uid; defer the enabled/disabled decision to the canonical
     *    save on apply. We deliberately skip disableForEntry() and
     *    markProtectedWithoutSecret() here: keying an empty placeholder by the
     *    draft uid would, on apply, wipe the canonical's real secret when the
     *    editor merely opened the entry without touching the password. The
     *    typed password is moved onto the canonical by
     *    {@see \iceboxind\sesame\services\Secrets::reconcileAppliedDraft()},
     *    fired from Drafts::EVENT_AFTER_APPLY_DRAFT.
     *  - **Canonical** — the real save: full store/clear/fail-closed logic.
     */
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        if (ElementHelper::isRevision($element)) {
            parent::afterElementSave($element, $isNew);
            return;
        }

        $uid = $element->uid ?? null;
        if ($uid) {
            /** @var ProtectValue $value */
            $value = $element->getFieldValue($this->handle);
            $secrets = Plugin::getInstance()->secrets;

            if (ElementHelper::isDraft($element)) {
                // Staging: remember a freshly typed password ONLY while protection
                // is on. If the editor typed a password but also switched the
                // field off, stage nothing — otherwise reconcile would resurrect
                // the page on publish against the editor's intent.
                if ($value->enabled && $value->password !== null) {
                    $secrets->storeForEntry($uid, $value->password);
                }
            } elseif (!$value->enabled) {
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
     * When a protected CANONICAL entry is hard-deleted, remove its stored secret
     * so the encrypted password doesn't linger in {{%sesame_entry_secrets}}
     * forever (the table has no cascade FK — it's keyed by element UID, not id).
     * Soft-deleted entries keep their protection (they can be restored).
     *
     * A DRAFT/revision is deliberately skipped: `Drafts::applyDraft()` hard-deletes
     * the provisional draft BEFORE it fires EVENT_AFTER_APPLY_DRAFT (Craft
     * 5.10.11, Drafts.php:340 then :361), so clearing here would wipe the staged
     * password out from under the reconcile step that is supposed to move it onto
     * the canonical. A draft that is discarded instead of applied leaves its row
     * orphaned, and {@see \iceboxind\sesame\services\Secrets::purgeOrphans()}
     * sweeps it in GC.
     */
    public function afterElementDelete(ElementInterface $element): void
    {
        if ($element->uid && $element->hardDelete && !ElementHelper::isDraftOrRevision($element)) {
            Plugin::getInstance()->secrets->clearForEntry($element->uid);
        }

        parent::afterElementDelete($element);
    }
}
