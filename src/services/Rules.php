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

    /** Insert or update, keyed by `$rule->id` (insert when null). Assigns id/uid back onto the model. */
    public function save(Rule $rule): bool
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
            $db->createCommand()->update(Install::RULES_TABLE, $data, ['id' => $rule->id])->execute();
        } else {
            $data['uid'] = $rule->uid ?: StringHelper::UUID();
            $data['dateCreated'] = $now;
            $db->createCommand()->insert(Install::RULES_TABLE, $data)->execute();
            $rule->id = (int) $db->getLastInsertID();
            $rule->uid = $data['uid'];
        }

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
        return true;
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
            'codesJson' => $row['codesJson'],
            'unlockUntil' => $row['unlockUntil'],
            'rememberMe' => (bool) $row['rememberMe'],
        ]);
    }
}
