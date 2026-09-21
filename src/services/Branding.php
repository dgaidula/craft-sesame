<?php

namespace iceboxind\sesame\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use iceboxind\sesame\migrations\Install;
use iceboxind\sesame\models\Scope;
use iceboxind\sesame\Plugin;

/**
 * Challenge-screen branding (P1.3, Pro). A site default lives in the single-row
 * {{%sesame_branding}} table (editable on production, unlike project-config
 * settings); each rule may override any field ({@see \iceboxind\sesame\models\Rule}
 * brand* columns; the per-rule intro override is the rule's `message`).
 *
 * {@see resolveForScope()} produces the effective values the challenge template
 * uses — per-rule override, else site default, else null (the template falls
 * back to its built-in look). Rendered on every edition; only authoring is
 * Pro-gated (in the controllers).
 */
class Branding extends Component
{
    /**
     * Sanitizes a submitted accent to a SAFE CSS colour, or null. The value is
     * injected into a `<style>` block on the challenge screen, so anything that
     * could break out of a CSS declaration ({ } ; < " …) is rejected. Accepts a
     * hex colour, a bare colour keyword, or an rgb()/hsl() functional value.
     */
    public static function sanitizeAccent(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (
            preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)
            || preg_match('/^[a-zA-Z]{3,20}$/', $value)
            || preg_match('~^(?:rgb|hsl)a?\([0-9.,%\s/]+\)$~i', $value)
        ) {
            return $value;
        }
        return null;
    }

    /**
     * The raw site-default row as an array (nulls when unset / no row yet).
     *
     * @return array{logoId:?int,heading:?string,intro:?string,accent:?string}
     */
    public function siteDefaults(): array
    {
        $row = (new Query())
            ->select(['logoId', 'heading', 'intro', 'accent'])
            ->from(Install::BRANDING_TABLE)
            ->one();

        return [
            'logoId' => isset($row['logoId']) ? (int) $row['logoId'] : null,
            'heading' => $row['heading'] ?? null,
            'intro' => $row['intro'] ?? null,
            'accent' => $row['accent'] ?? null,
        ];
    }

    /** Upserts the single site-default row. Empty strings are stored as null (= inherit built-in). */
    public function saveSiteDefaults(?int $logoId, ?string $heading, ?string $intro, ?string $accent): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $data = [
            'logoId' => $logoId ?: null,
            'heading' => $heading ?: null,
            'intro' => $intro ?: null,
            'accent' => $accent ?: null,
            'dateUpdated' => $now,
        ];

        $id = (new Query())->select(['id'])->from(Install::BRANDING_TABLE)->scalar();
        if ($id) {
            $db->createCommand()->update(Install::BRANDING_TABLE, $data, ['id' => $id])->execute();
        } else {
            $data['dateCreated'] = $now;
            $data['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(Install::BRANDING_TABLE, $data)->execute();
        }
    }

    /**
     * The effective branding for a challenge: per-rule override ?? site default
     * ?? null. Intro = the rule's own message (per-rule) ?? the site intro. The
     * logo is resolved to a URL (null if unset or the asset was deleted).
     *
     * @return array{accent:?string,heading:?string,intro:?string,logoUrl:?string}
     */
    public function resolveForScope(Scope $scope): array
    {
        $site = $this->siteDefaults();

        $rule = $scope->type === 'rule'
            ? Plugin::getInstance()->rules->getByUid($scope->uid)
            : null;

        $accent = ($rule?->brandAccent) ?: ($site['accent'] ?: null);
        $heading = ($rule?->brandHeading) ?: ($site['heading'] ?: null);
        $intro = ($scope->message ?: null) ?: ($site['intro'] ?: null);
        $logoId = ($rule?->brandLogoId) ?: ($site['logoId'] ?: null);

        $logoUrl = null;
        if ($logoId) {
            $asset = Craft::$app->getAssets()->getAssetById((int) $logoId);
            $logoUrl = $asset?->getUrl();
        }

        return [
            'accent' => $accent ?: null,
            'heading' => $heading ?: null,
            'intro' => $intro ?: null,
            'logoUrl' => $logoUrl ?: null,
        ];
    }
}
