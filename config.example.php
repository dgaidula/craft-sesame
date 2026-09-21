<?php

/**
 * Sesame config override example.
 *
 * Copy to config/sesame.php in the host site to override Settings values
 * per environment. This file is a template only — it is NOT loaded by Craft
 * and ships purely as documentation; delete or keep it as you like.
 *
 * Anything set here overrides the plugin's saved Settings (project config) for
 * the current environment, which is how you keep dev-owned values out of
 * project config entirely, or vary them by environment without touching
 * the CP.
 *
 * @see \dgaidula\sesame\models\Settings
 */

return [
    // Global overrides, applied in every environment unless overridden below.
    '*' => [
        // Relabels the plugin in the CP (sidebar, Plugins screen, settings
        // breadcrumb). Blank keeps "Sesame".
        'pluginName' => '',

        // false (default) = encrypt-at-rest, reversible for CP "reveal".
        // true = bcrypt, write-only, no recall.
        'hashPasswords' => false,

        // Failed unlock attempts allowed per IP + protected page before
        // throttling kicks in, and the window (seconds) it applies over.
        'attemptLimit' => 8,
        'attemptWindow' => 300,

        // PRO. 0 (default) = remember-me off entirely, even for rules with
        // their own "Remember me" switch on. Otherwise the cookie lifetime,
        // in seconds, once a visitor checks "Remember me" on a rule that
        // opted in.
        'rememberMeDuration' => 0,

        // PRO. Access log rows older than this (days) are purged during
        // Craft's garbage collection. 0 or less keeps every row indefinitely.
        'accessLogRetentionDays' => 30,
    ],

    // Dev-only overrides — this branch is skipped in staging/production.
    // Handy for a looser throttle while testing locally.
    'dev' => [
        // 'attemptLimit' => 100,
    ],
];
