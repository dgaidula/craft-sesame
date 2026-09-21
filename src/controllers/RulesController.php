<?php

namespace iceboxind\sesame\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use iceboxind\sesame\models\Rule;
use iceboxind\sesame\Plugin;
use iceboxind\sesame\services\Branding;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP screen for the rule set — the "no template code" way to protect a page
 * (a URI glob, or a section/entry-type handle) behind a shared password.
 * This is the plugin's main CP section; the per-entry Protect field is
 * configured on the entry itself (`fields/Protect` + `_field/protect-input.twig`),
 * not here.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_RULES);
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('sesame/rules/index', [
            'rules' => Plugin::getInstance()->rules->all(),
        ]);
    }

    public function actionEdit(?string $uid = null): Response
    {
        $rule = null;
        if ($uid !== null) {
            $rule = Plugin::getInstance()->rules->getByUid($uid);
            if ($rule === null) {
                throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
            }
        }

        return $this->renderEdit($rule ?? new Rule());
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $uid = $request->getBodyParam('uid') ?: null;
        $isNew = $uid === null;
        $rule = $isNew ? new Rule() : Plugin::getInstance()->rules->getByUid((string) $uid);
        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
        }

        // Capture what the rule targeted BEFORE the posted values overwrite it,
        // so the Lite authoring gate below can tell an unchanged existing
        // pattern rule (password rotation is fine) from one being created or
        // re-targeted (Pro only).
        $storedMatchType = $rule->matchType;
        $storedPattern = $rule->pattern;

        $rule->label = (string) $request->getBodyParam('label', $rule->label);
        $rule->matchType = (string) $request->getBodyParam('matchType', $rule->matchType);
        $rule->pattern = (string) $request->getBodyParam('pattern', $rule->pattern);
        $rule->message = $request->getBodyParam('message') ?: null;
        $rule->templateOverride = $request->getBodyParam('templateOverride') ?: null;
        $rule->enabled = (bool) $request->getBodyParam('enabled', false);
        // PRO. Lite ignores whatever posts here — forced false so a Lite
        // install can never end up with rememberMe=true on a saved rule
        // (the field is only rendered on the edit screen when isPro).
        $rule->rememberMe = Plugin::getInstance()->isPro() && (bool) $request->getBodyParam('rememberMe', false);

        // PRO. Scheduled lock/unlock (P1.1). Authoring is Pro-gated: on Lite the
        // fields aren't rendered and aren't read here, so $rule keeps whatever the
        // stored row had — a grandfathered schedule survives a downgrade and is
        // still enforced (Rules::match honors it on every edition), it just can't
        // be changed. Stored as UTC; an empty/cleared value clears that bound.
        if (Plugin::getInstance()->isPro()) {
            $from = DateTimeHelper::toDateTime($request->getBodyParam('protectFrom'));
            $until = DateTimeHelper::toDateTime($request->getBodyParam('protectUntil'));
            $rule->protectFrom = $from ? Db::prepareDateForDb($from) : null;
            $rule->protectUntil = $until ? Db::prepareDateForDb($until) : null;
            if ($from && $until && $from >= $until) {
                // An empty window would mean the rule never protects.
                $rule->addError('protectUntil', Craft::t('sesame', 'The unlock time must be after the lock time.'));
            }

            // PRO. Per-rule challenge-screen branding overrides (P1.3). Read only
            // on Pro; on Lite the fields aren't rendered so a grandfathered
            // override is preserved through the hydrated model.
            $rule->brandHeading = trim((string) $request->getBodyParam('brandHeading', '')) ?: null;
            $rule->brandAccent = Branding::sanitizeAccent($request->getBodyParam('brandAccent'));
            $logoIds = $request->getBodyParam('brandLogoId');
            $rule->brandLogoId = is_array($logoIds) ? ((int) ($logoIds[0] ?? 0) ?: null) : null;
        }

        // The single Password field is the rule's "code one" (see the Codes
        // service). A blank password on an EXISTING rule leaves code one alone; a
        // new rule requires one. The actual store/rotate happens AFTER the rule
        // saves (code one needs the rule's uid), below.
        $password = (string) $request->getBodyParam('password', '');
        if ($password === '' && $isNew) {
            $rule->addError('secret', Craft::t('sesame', 'A password is required.'));
        }

        // Did this save change WHAT the rule protects (not just its password)?
        $targetChanged = $rule->matchType !== $storedMatchType || $rule->pattern !== $storedPattern;

        // Lite AUTHORING gate (P0.5). Pattern rules — a `*` wildcard, a whole
        // section, or a whole entry type — are a Pro feature; Lite keeps exact-URI
        // rules and the per-entry Protect field. This gates AUTHORING only:
        // Rules::match() has no edition check, so a pattern rule created while Pro
        // keeps protecting after a downgrade, and Lite may still rotate its
        // password / enable / disable / delete / reorder it. Only creating a
        // pattern rule, or changing a rule's target into/within pattern territory,
        // is blocked. Exact URI = matchType 'uri' with no '*'.
        if (!Plugin::getInstance()->isPro()) {
            $incomingIsPattern = $rule->matchType !== 'uri' || str_contains($rule->pattern, '*');
            if ($incomingIsPattern && ($isNew || $targetChanged)) {
                $rule->addError('pattern', Craft::t('sesame', 'Wildcard, whole-section, and whole-entry-type rules are a Sesame Pro feature. On the free edition, protect an exact URL (no “*”), or use the per-entry “Sesame Protection” field.'));
            }
        }

        // Bump the revocation epoch when the rule's TARGET changes (retargeting
        // e.g. `members` → `board-minutes/*` would otherwise leave old unlocks
        // valid for content they were never granted). A PASSWORD change bumps the
        // epoch too, but that happens in Codes::changePassword() below, not here.
        $bumpEpoch = !$isNew && $targetChanged;

        if ($rule->hasErrors() || !Plugin::getInstance()->rules->save($rule, $bumpEpoch)) {
            Craft::$app->getSession()->setError(Craft::t('sesame', 'Couldn’t save the rule.'));
            return $this->renderEdit($rule);
        }

        // Code one carries the rule's password. Create it on a new rule; rotate it
        // when a non-blank password was posted on an edit (changePassword bumps
        // the rule epoch). A blank password on an edit leaves code one untouched.
        $codes = Plugin::getInstance()->codes;
        $ruleUid = (string) $rule->uid;
        if ($isNew) {
            $codes->add($ruleUid, $password, Craft::t('sesame', 'Default'));
        } elseif ($password !== '') {
            $codeOne = $codes->codeOne($ruleUid);
            if ($codeOne !== null) {
                $codes->changePassword($codeOne->uid, $password);
            } else {
                $codes->add($ruleUid, $password, Craft::t('sesame', 'Default'));
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('sesame', 'Rule saved.'));
        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');

        Plugin::getInstance()->rules->delete($uid);
        Craft::$app->getSession()->setNotice(Craft::t('sesame', 'Rule deleted.'));

        return $this->redirectToPostedUrl();
    }

    /** Moves one rule up or down one place, swapping it with its neighbor. */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $uid = (string) $request->getRequiredBodyParam('uid');
        $direction = (string) $request->getBodyParam('direction', 'down');

        $uids = array_keys(Plugin::getInstance()->rules->all());
        $index = array_search($uid, $uids, true);
        if ($index !== false) {
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if (isset($uids[$swapWith])) {
                [$uids[$index], $uids[$swapWith]] = [$uids[$swapWith], $uids[$index]];
                Plugin::getInstance()->rules->reorder($uids);
            }
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * "Revoke all access" for one rule (both editions): bumps the rule's
     * revocation epoch so every outstanding session, remember-me cookie, and
     * magic link is cut off at once — the password itself is unchanged, so the
     * next visitor just re-enters it. POST + JSON, permission-gated by
     * `beforeAction()` like every other action here.
     */
    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');
        if (!Plugin::getInstance()->rules->revoke($uid)) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
        }

        return $this->asJson(['ok' => true]);
    }

    // --- Named-code management (the code-list UI on the rule edit screen) ---
    // Thin wrappers over the Codes service; all POST + JSON, permission-gated by
    // beforeAction(). Edition gate per the decided line: only ADDING a second
    // code is Pro — changing (relabel/expiry) is Pro too, but REVOKING and
    // DELETING an existing code are allowed on every edition, so a site that
    // drops to Lite can still cut off access.

    /**
     * Adds a named code (label + password + optional expiry) to a rule. Adding a
     * SECOND code (when the rule already has one) is a Pro feature; code one
     * itself is created with the rule.
     */
    public function actionAddCode(): Response
    {
        [$request, $ruleUid] = $this->requireCodeManagement(true, false);

        $codes = Plugin::getInstance()->codes;
        if (!Plugin::getInstance()->isPro() && $codes->count($ruleUid) >= 1) {
            return $this->asJson(['error' => Craft::t('sesame', 'Multiple codes per rule are a Sesame Pro feature.')]);
        }

        $password = (string) $request->getBodyParam('password', '');
        if ($password === '') {
            return $this->asJson(['error' => Craft::t('sesame', 'A password is required.')]);
        }
        $label = trim((string) $request->getBodyParam('label', '')) ?: null;

        if ($codes->add($ruleUid, $password, $label, $this->postedExpiry()) === null) {
            return $this->asJson(['error' => Craft::t('sesame', 'Couldn’t add the code — a rule can have at most {n} codes.', ['n' => \iceboxind\sesame\services\Codes::MAX_CODES])]);
        }

        return $this->asJson(['ok' => true]);
    }

    /** PRO. Relabels a code and/or changes its expiry. */
    public function actionUpdateCode(): Response
    {
        [$request] = $this->requireCodeManagement(false, true);

        $codeId = (string) $request->getRequiredBodyParam('codeId');
        $codes = Plugin::getInstance()->codes;
        $codes->relabel($codeId, trim((string) $request->getBodyParam('label', '')) ?: null);
        $codes->setExpiry($codeId, $this->postedExpiry());

        return $this->asJson(['ok' => true]);
    }

    /** Revokes a code (cuts off its holders; the code stays listed as revoked). Every edition. */
    public function actionRevokeCode(): Response
    {
        [$request] = $this->requireCodeManagement(false, false);
        Plugin::getInstance()->codes->revoke((string) $request->getRequiredBodyParam('codeId'));
        return $this->asJson(['ok' => true]);
    }

    /** Deletes a code outright (refused for a rule's last/only code). Every edition. */
    public function actionDeleteCode(): Response
    {
        [$request] = $this->requireCodeManagement(false, false);
        $ok = Plugin::getInstance()->codes->delete((string) $request->getRequiredBodyParam('codeId'));
        return $this->asJson($ok ? ['ok' => true] : ['error' => Craft::t('sesame', 'A rule must keep at least one code.')]);
    }

    /**
     * Shared preamble for the code-management actions: POST + JSON, an optional
     * Pro gate ($requirePro), and — when $needRule — the `uid` rule param.
     *
     * @return array{0:\craft\web\Request,1:string}
     */
    private function requireCodeManagement(bool $needRule = true, bool $requirePro = true): array
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        if ($requirePro && !Plugin::getInstance()->isPro()) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Named codes are a Sesame Pro feature.'));
        }

        $request = Craft::$app->getRequest();
        $ruleUid = '';
        if ($needRule) {
            $ruleUid = (string) $request->getRequiredBodyParam('uid');
            if (Plugin::getInstance()->rules->getByUid($ruleUid) === null) {
                throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
            }
        }

        return [$request, $ruleUid];
    }

    /** Parses the posted `expiresAt` into a UTC datetime string for storage, or null when blank. */
    private function postedExpiry(): ?string
    {
        $v = Craft::$app->getRequest()->getBodyParam('expiresAt');

        // The code-management UI posts a bare `YYYY-MM-DD` from an <input
        // type=date>. Interpret it in the SYSTEM time zone (not UTC) and pin it to
        // the END of that day: "expires Oct 1" then means end of Oct 1 locally.
        // Parsed as UTC midnight instead, it displays as the day before in any
        // zone west of UTC, and — because the list re-posts the displayed date —
        // the expiry walks back a day on every save. A full datetime is honoured
        // as given.
        $dt = DateTimeHelper::toDateTime($v, true);
        if ($dt === false) {
            return null;
        }
        if (is_string($v) && preg_match('/^\s*\d{4}-\d{2}-\d{2}\s*$/', $v)) {
            $dt->setTime(23, 59, 59);
        }

        return Db::prepareDateForDb($dt);
    }

    /**
     * PRO. Mints a shareable magic-link URL for one rule ("Copy shareable
     * link" on the Rules edit screen) — POST-only, JSON response, gated by
     * the same `sesame:manageRules` permission as every other action here
     * (`beforeAction()`), plus an explicit `isPro()` check since this is a
     * Pro-only capability that a Lite install must refuse even if someone
     * guesses the action route directly.
     */
    public function actionMintLink(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->isPro()) {
            return $this->asJson(['error' => Craft::t('sesame', 'Shareable links are a Sesame Pro feature.')]);
        }

        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid');
        $rule = Plugin::getInstance()->rules->getByUid($uid);
        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('sesame', 'Rule not found.'));
        }

        // A link is minted for ONE code, so revoking that code kills its links.
        // The management UI (a later pass) will let the editor pick a code; for
        // now default to a posted codeId, else code one. The code must belong to
        // this rule and be active.
        $codeId = (string) Craft::$app->getRequest()->getBodyParam('codeId', '');
        $code = $codeId !== ''
            ? Plugin::getInstance()->codes->getByUid($codeId)
            : Plugin::getInstance()->codes->codeOne($uid);
        if ($code === null || $code->ruleUid !== $uid || !$code->isActive()) {
            return $this->asJson(['error' => Craft::t('sesame', 'That code is no longer available to link.')]);
        }

        // Default 7 days, editor-adjustable per mint via `ttlDays`, capped at a
        // year so a fat-fingered value can't mint an effectively-permanent link.
        $ttlDays = max(1, min(365, (int) Craft::$app->getRequest()->getBodyParam('ttlDays', 7)));
        $ttl = $ttlDays * 86400;

        // A URI rule with no glob has one unambiguous target page — link
        // straight to it. A globbed URI, or a section/entry-type rule,
        // protects many pages at once with no single "the" page, so the
        // link just lands on the unlock and sends the visitor to the site
        // root; they're unlocked for everything the rule covers either way.
        // The target must be site-relative with a leading slash: a Craft entry
        // URI is stored WITHOUT one ('members/handbook'), and GateController's
        // open-redirect guard (safeReturn) rejects a bare relative path, which
        // is why an exact-URI link used to dump the visitor on the home page.
        $target = ($rule->matchType === 'uri' && !str_contains($rule->pattern, '*'))
            ? '/' . ltrim($rule->pattern, '/')
            : '';

        $token = Plugin::getInstance()->gate->signMagicLink($rule->toScope(), $ttl, $target, $code->uid);

        return $this->asJson([
            'url' => UrlHelper::siteUrl('sesame/gate/link', ['t' => $token]),
            'ttlDays' => $ttlDays,
        ]);
    }

    /**
     * Returns a code's current password for CP display — decrypted, encrypt-mode
     * only. Hash-mode codes are write-only, so this returns null and the edit
     * screen shows "written-only" instead. Defaults to the rule's code one (the
     * single Password field); the management UI (later pass) passes a codeId.
     */
    public function actionRevealPassword(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // Disclosing a stored secret is sensitive: require a re-authenticated
        // (elevated) session, so an unattended or hijacked CP session can't
        // reveal passwords without the user re-entering theirs. The edit screen
        // drives this through Craft.elevatedSessionManager before it fetches.
        $this->requireElevatedSession();

        $uid = (string) Craft::$app->getRequest()->getRequiredBodyParam('uid'); // rule uid
        $codeId = (string) Craft::$app->getRequest()->getBodyParam('codeId', '');
        $codes = Plugin::getInstance()->codes;

        $rule = Plugin::getInstance()->rules->getByUid($uid);
        $code = $codeId !== '' ? $codes->getByUid($codeId) : $codes->codeOne($uid);
        // Guard: the code must belong to the named rule.
        $password = ($rule !== null && $code !== null && $code->ruleUid === $uid)
            ? $codes->reveal($code->uid)
            : null;

        // Audit the disclosure (Pro only, like every other access-log write).
        if ($rule !== null) {
            Plugin::getInstance()->accessLog->record('reveal', $rule->toScope(), Craft::$app->getRequest(), $code?->uid);
        }

        return $this->asJson(['password' => $password]);
    }

    private function renderEdit(Rule $rule): Response
    {
        $entries = Craft::$app->getEntries();

        $codes = Plugin::getInstance()->codes;

        // Code one's storage mode drives the reveal-password UI (encrypt → can
        // reveal; hash → write-only). Null on a new rule (no code yet).
        $codeOne = $rule->uid ? $codes->codeOne((string) $rule->uid) : null;

        // Additional codes (2..N) for the Pro code-management list — code one is
        // managed by the top Password field, so it's dropped here. Prepared for
        // the template: expiry as a system-tz DateTime, and a status label.
        // Listed on EVERY edition (Fix 2): a site that dropped to Lite must be
        // able to see and revoke/delete codes it made while on Pro. Adding and
        // relabelling are still Pro-gated (in the actions + the template).
        $additionalCodes = [];
        if ($rule->uid) {
            $all = $codes->forRule((string) $rule->uid);
            array_shift($all); // drop code one
            foreach ($all as $c) {
                $additionalCodes[] = [
                    'uid' => $c->uid,
                    'label' => $c->label,
                    'expiresAt' => $c->expiresAt ? DateTimeHelper::toDateTime($c->expiresAt) : null,
                    'canReveal' => $c->secretMode === 'encrypt',
                    'status' => $c->revokedAt !== null ? 'revoked' : ($c->isActive() ? 'active' : 'expired'),
                ];
            }
        }

        $sectionOptions = array_map(
            static fn($section) => ['label' => $section->name, 'value' => $section->handle],
            $entries->getAllSections()
        );
        $entryTypeOptions = array_map(
            static fn($entryType) => ['label' => $entryType->name, 'value' => $entryType->handle],
            $entries->getAllEntryTypes()
        );

        return $this->renderTemplate('sesame/rules/_edit', [
            'rule' => $rule,
            'isNew' => $rule->uid === null,
            'sectionOptions' => $sectionOptions,
            'entryTypeOptions' => $entryTypeOptions,
            'isPro' => Plugin::getInstance()->isPro(),
            // Whether the (stored) rule is a pattern rule — the Lite edit screen
            // locks its target so a password rotation can't accidentally
            // re-author it (see the actionSave gate + _edit.twig).
            'ruleIsPattern' => $rule->matchType !== 'uri' || str_contains((string) $rule->pattern, '*'),
            // Schedule bounds as DateTimes (system tz) for the dateTimeField
            // widgets — stored as UTC strings.
            'protectFromDate' => $rule->protectFrom ? DateTimeHelper::toDateTime($rule->protectFrom) : null,
            'protectUntilDate' => $rule->protectUntil ? DateTimeHelper::toDateTime($rule->protectUntil) : null,
            // 'encrypt' | 'hash' | null — code one's storage mode for the reveal UI.
            'codeOneMode' => $codeOne?->secretMode,
            // Codes 2..N for the Pro code-management list (empty on Lite / new).
            'additionalCodes' => $additionalCodes,
            // The rule's branding logo asset (for the Pro elementSelectField), or null.
            'brandLogo' => $rule->brandLogoId ? Craft::$app->getAssets()->getAssetById($rule->brandLogoId) : null,
        ]);
    }
}
