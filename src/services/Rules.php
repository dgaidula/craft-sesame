<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\models\Rule;
use iceboxind\sesame\models\Scope;
use iceboxind\sesame\Plugin;
use yii\db\Expression;

/**
 * Reads/writes {{%sesame_rules}} and matches the current entry against them.
 *
 * This is the content-layer, multi-row cousin of the single-row upsert
 * pattern ({@see \iceboxind\sesame\services\Secrets} for the entry-secrets
 * table; Downtoll's FormConfig for the single-row shape) — editable on
 * production regardless of `allowAdminChanges`, entirely plugin-owned.
 */
class Rules extends Component
{
    /** @var array<string,Rule>|null Request-scoped cache, keyed by uid, ordered by sortOrder. */
    private ?array $rules = null;

    /** @return Rule[] All rules, ordered by sortOrder, keyed by uid. */
    public function all(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rows = (new Query())
            ->from(Install::RULES_TABLE)
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        $this->rules = [];
        foreach ($rows as $row) {
            $this->rules[$row['uid']] = $this->rowToRule($row);
        }

        return $this->rules;
    }

    public function getByUid(string $uid): ?Rule
    {
        return $this->all()[$uid] ?? null;
    }

    /**
     * Insert or update, keyed by `$rule->id` (insert when null). Assigns id/uid
     * back onto the model.
     *
     * @param bool $bumpEpoch Increments the revocation epoch atomically as part
     * of the same UPDATE (`epoch = epoch + 1`), cutting off every outstanding
     * session / remember-me cookie / magic link. Pass true only when the
     * credential actually changed (a new password) — never for a plain
     * enable/disable, reorder, or label edit. Ignored on insert (a brand-new
     * rule starts at epoch 0 with nobody holding an unlock). The `epoch` column
     * is otherwise left untouched, so the model never writes back a stale
     * absolute value over a concurrent bump.
     */
    public function save(Rule $rule, bool $bumpEpoch = false): bool
    {
        if (!$rule->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $data = [
            'enabled' => (bool) $rule->enabled,
            'sortOrder' => $rule->sortOrder,
            'label' => $rule->label,
            'matchType' => $rule->matchType,
            'pattern' => $rule->pattern,
            'secret' => $rule->secret,
            'secretMode' => $rule->secretMode,
            'message' => $rule->message,
            'templateOverride' => $rule->templateOverride,
            'codesJson' => $rule->codesJson,
            'unlockUntil' => $rule->unlockUntil,
            'rememberMe' => (bool) $rule->rememberMe,
            'dateUpdated' => $now,
        ];

        if ($rule->id) {
            if ($bumpEpoch) {
                $data['epoch'] = new Expression('[[epoch]] + 1');
            }
            $db->createCommand()->update(Install::RULES_TABLE, $data, ['id' => $rule->id])->execute();
        } else {
            $data['uid'] = $rule->uid ?: StringHelper::UUID();
            $data['dateCreated'] = $now;
            $db->createCommand()->insert(Install::RULES_TABLE, $data)->execute();
            $rule->id = (int) $db->getLastInsertID();
            $rule->uid = $data['uid'];
        }

        $this->rules = null;
        $this->purgeStaticCache();
        return true;
    }

    /**
     * Explicit "revoke all access": bumps the rule's epoch atomically without
     * changing the password, so everyone currently unlocked (session,
     * remember-me cookie, or outstanding magic link) is cut off and must
     * re-enter the same password. Both editions — revocation is a security
     * property, not a Pro convenience.
     */
    public function revoke(string $uid): bool
    {
        $rule = $this->getByUid($uid);
        if (!$rule || !$rule->id) {
            return false;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Install::RULES_TABLE, ['epoch' => new Expression('[[epoch]] + 1')], ['id' => $rule->id])
            ->execute();
        $this->rules = null;
        return true;
    }

    public function delete(string $uid): bool
    {
        $rule = $this->getByUid($uid);
        if (!$rule || !$rule->id) {
            return false;
        }

        Craft::$app->getDb()->createCommand()->delete(Install::RULES_TABLE, ['id' => $rule->id])->execute();
        $this->rules = null;
        $this->purgeStaticCache();
        return true;
    }

    /** @param string[] $uids The full rule set, in the new order. */
    public function reorder(array $uids): bool
    {
        $db = Craft::$app->getDb();
        foreach (array_values($uids) as $i => $uid) {
            $rule = $this->getByUid($uid);
            if (!$rule) {
                continue;
            }
            $db->createCommand()->update(Install::RULES_TABLE, ['sortOrder' => $i], ['id' => $rule->id])->execute();
        }

        $this->rules = null;
        $this->purgeStaticCache();
        return true;
    }

    /**
     * Any rule change can flip a URL between public and protected, so drop the
     * static cache (P0.1). This is a BACKSTOP, not the primary defense: the
     * cacheable-request veto ({@see \iceboxind\sesame\services\StaticCache})
     * already refuses to serve a protected URL from cache. It matters only for
     * a section/entry-type rule on a page cached before the rule existed, if
     * the matched element isn't resolvable when the veto runs. No-op without
     * Blitz; a full clear is the honest fallback since a glob/section/type
     * rule's affected URIs aren't enumerable here.
     */
    private function purgeStaticCache(): void
    {
        Plugin::getInstance()->staticCache->purgeAll();
    }

    /**
     * Evaluates enabled rules in sortOrder and returns the first match, or
     * null if nothing matches. Per-entry field overrides are Gate's concern,
     * not this method's — see {@see \iceboxind\sesame\services\Gate::isProtected()}.
     */
    public function match(Entry $entry): ?Scope
    {
        $pathInfo = $this->currentPathInfo();
        $uri = (string) ($entry->uri ?? '');

        foreach ($this->all() as $rule) {
            if (!$rule->enabled) {
                continue;
            }

            $matched = match ($rule->matchType) {
                'section' => ($section = $entry->getSection()) !== null && $section->handle === $rule->pattern,
                'entryType' => $entry->getType()->handle === $rule->pattern,
                'uri' => $this->matchesUri($rule->pattern, $uri) || $this->matchesUri($rule->pattern, $pathInfo),
                default => false,
            };

            if ($matched) {
                return $rule->toScope();
            }
        }

        return null;
    }

    /**
     * Element-free protection check for a request path: true if any enabled
     * URI-pattern rule matches. Used by the static-cache veto
     * ({@see \iceboxind\sesame\services\StaticCache}) at a point in the request
     * lifecycle where the matched element may not be resolvable yet. Section /
     * entry-type / per-entry protection needs the element, so those are covered
     * by the element path in the veto, not here.
     */
    public function uriIsProtected(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }

        foreach ($this->all() as $rule) {
            if ($rule->enabled && $rule->matchType === 'uri' && $this->matchesUri($rule->pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function currentPathInfo(): string
    {
        $request = Craft::$app->getRequest();
        return $request->getIsConsoleRequest() ? '' : $request->getPathInfo();
    }

    /**
     * Translates a `*` glob to an anchored, case-insensitive regex; everything
     * else matches literally.
     *
     * A trailing `/*` is special-cased to mean "this base path AND everything
     * under it" — so `district-resources/*` matches the landing entry at URI
     * `district-resources` as well as `district-resources/child`. Without this
     * the bare base (whose Craft URI has no trailing slash) would fall through
     * unprotected, which is exactly the page the admin most wants gated and is
     * what the edit-screen help text promises.
     */
    private function matchesUri(string $pattern, string $uri): bool
    {
        if ($uri === '' || $pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '/*')) {
            $base = substr($pattern, 0, -2);
            $regex = '#^' . preg_quote($base, '#') . '(/.*)?$#i';
        } else {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        }

        return (bool) preg_match($regex, $uri);
    }

    private function rowToRule(array $row): Rule
    {
        return new Rule([
            'id' => (int) $row['id'],
            'uid' => $row['uid'],
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sortOrder'],
            'label' => (string) $row['label'],
            'matchType' => (string) $row['matchType'],
            'pattern' => (string) $row['pattern'],
            'secret' => (string) $row['secret'],
            'secretMode' => (string) $row['secretMode'],
            'message' => $row['message'],
            'templateOverride' => $row['templateOverride'],
            'epoch' => (int) ($row['epoch'] ?? 0),
            'codesJson' => $row['codesJson'],
            'unlockUntil' => $row['unlockUntil'],
            'rememberMe' => (bool) $row['rememberMe'],
        ]);
    }
}
