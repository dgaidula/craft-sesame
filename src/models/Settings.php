<?php

namespace iceboxind\sesame\models;

use craft\base\Model;

/**
 * Global plugin settings (PROJECT CONFIG — dev-owned, locked on prod when
 * `allowAdminChanges` is off; that's intentional, these are deploy-time
 * values). Editor-facing content (rules, per-entry secrets) lives in the
 * plugin's own DB tables instead — see
 * {@see \iceboxind\sesame\services\Rules} and {@see \iceboxind\sesame\services\Secrets}.
 */
class Settings extends Model
{
    /**
     * Overrides the plugin's name in the CP — the sidebar nav item, the
     * Plugins screen, and the settings breadcrumb. Blank = "Sesame".
     *
     * Set it in the site's `config/sesame.php` so it is version-controlled
     * and survives with `allowAdminChanges` off.
     */
    public string $pluginName = '';

    /**
     * How passwords are stored (PATTERNS.md §4). false (default) =
     * encrypt-at-rest — reversible, so an admin can reveal/re-share a shared
     * page password later; the right call for a shared, recall-needed
     * secret. true = bcrypt (write-only, no recall) for sites that want that
     * instead.
     */
    public bool $hashPasswords = false;

    /** Failed unlock attempts allowed per IP+scope before throttling kicks in. */
    public int $attemptLimit = 8;

    /** Throttle window, in seconds, that `attemptLimit` applies over. */
    public int $attemptWindow = 300;

    // --- Pro (unused by Lite; kept here so Settings has a stable shape across editions) ---

    /** PRO. Remember-me cookie lifetime, in seconds. 0 = off. Read by {@see \iceboxind\sesame\services\Gate::unlock()}. */
    public int $rememberMeDuration = 0;

    /** PRO. Access log retention, in days. 0 or less disables the purge (unbounded retention). Read by {@see \iceboxind\sesame\services\AccessLog::purgeExpired()}, which runs on `Gc::EVENT_RUN`. */
    public int $accessLogRetentionDays = 30;

    /** PRO. reCAPTCHA v3 secret — literal or `$ENV_VAR`. TODO Pro: not yet read anywhere — see the seam noted in `GateController::actionUnlock()`. */
    public string $recaptchaSecret = '';

    /** PRO. reCAPTCHA v3 site key (public) — literal or `$ENV_VAR`. TODO Pro: not yet read anywhere — see the seam noted in `GateController::actionUnlock()`. */
    public string $recaptchaSiteKey = '';

    // TODO Pro: CP-editable screen branding (logo / intro copy / accent
    // color) would live here as settings and be read by
    // `gate/challenge.twig` in place of its current hardcoded CSS custom
    // properties. Not built — every install shares the one default look
    // (still fully overridable per-site/per-rule via a template override).

    public function rules(): array
    {
        return [
            [['pluginName', 'recaptchaSecret', 'recaptchaSiteKey'], 'string'],
            [['hashPasswords'], 'boolean'],
            [['attemptLimit'], 'integer', 'min' => 1],
            [['attemptWindow'], 'integer', 'min' => 30],
            [['rememberMeDuration'], 'integer', 'min' => 0],
            [['accessLogRetentionDays'], 'integer'],
        ];
    }
}
