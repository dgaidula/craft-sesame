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
     * The submit-button text colours the accent must stay legible against — the
     * light and dark values of `--sesame-accent-text` in gate/challenge.twig. A
     * brand accent overrides `--sesame-accent` in BOTH themes (one value, last
     * `:root` wins), but not the text on it, so it has to read against both.
     */
    private const ACCENT_ON_LIGHT = [255, 255, 255]; // #ffffff
    private const ACCENT_ON_DARK = [15, 21, 22];     // #0f1516

    /** WCAG AA contrast for a UI component / graphical object. */
    private const MIN_CONTRAST = 3.0;

    /**
     * Sanitizes a submitted accent to a SAFE, LEGIBLE CSS colour, or null (=
     * inherit the built-in accent). Three gates:
     *  - Breakout safety: the value is injected into a `<style>` block, so only a
     *    hex or an rgb()/hsl() functional value is accepted — never a bare word.
     *    A bare keyword (`white`, `transparent`, `inherit`, or any typo) used to
     *    pass and could paint an invisible or broken button; dropping it also
     *    means every accepted value is parseable for the contrast check.
     *  - Column width: over 32 chars (the `accent` column) is rejected, not
     *    silently truncated into a broken colour.
     *  - Contrast: the accent backs the submit button whose text is a fixed light
     *    or dark colour depending on theme; a value that doesn't contrast both is
     *    dropped so the button never renders invisible. Non-string input (e.g.
     *    `accent[]=x`) is null, not a TypeError.
     */
    public static function sanitizeAccent(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 32) {
            return null;
        }

        $isHex = (bool) preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value);
        $isFunc = (bool) preg_match('~^(?:rgb|hsl)a?\([0-9.,%\s/]+\)$~i', $value);
        if (!$isHex && !$isFunc) {
            return null;
        }

        $rgb = self::colorToRgb($value);
        if ($rgb === null) {
            return null; // syntactically safe but unparseable for contrast → inherit
        }
        if (
            self::contrast($rgb, self::ACCENT_ON_LIGHT) < self::MIN_CONTRAST
            || self::contrast($rgb, self::ACCENT_ON_DARK) < self::MIN_CONTRAST
        ) {
            return null;
        }

        return $value;
    }

    /**
     * Validates a posted logo id (from an elementSelectField, which posts an
     * array) to the id of a real IMAGE asset, or null. Guards two 500s: a
     * non-element or deleted id would violate the `logoId` foreign key on save,
     * and a non-image asset (a PDF, a video) would render as a broken `<img>` on
     * the challenge screen.
     */
    public static function validImageAssetId(mixed $posted): ?int
    {
        $id = is_array($posted) ? (int) ($posted[0] ?? 0) : (int) $posted;
        if ($id <= 0) {
            return null;
        }
        $asset = Craft::$app->getAssets()->getAssetById($id);
        return ($asset !== null && $asset->kind === 'image') ? $id : null;
    }

    /** Parses a sanitized hex / rgb() / hsl() colour to `[r, g, b]` (0–255), or null. Alpha is ignored. */
    private static function colorToRgb(string $value): ?array
    {
        if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $value, $m)) {
            $h = $m[1];
            $len = strlen($h);
            if ($len === 3 || $len === 4) {
                return [hexdec($h[0] . $h[0]), hexdec($h[1] . $h[1]), hexdec($h[2] . $h[2])];
            }
            if ($len === 6 || $len === 8) {
                return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            }
            return null; // 5/7-digit hex isn't a real colour
        }

        if (preg_match('~^(rgb|hsl)a?\(([^)]*)\)$~i', $value, $m)) {
            $parts = preg_split('~[,\s/]+~', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false || count($parts) < 3) {
                return null;
            }
            if (strtolower($m[1]) === 'rgb') {
                $rgb = [];
                for ($i = 0; $i < 3; $i++) {
                    $p = $parts[$i];
                    $n = str_ends_with($p, '%') ? ((float) $p) * 2.55 : (float) $p;
                    $rgb[] = (int) max(0, min(255, round($n)));
                }
                return $rgb;
            }
            return self::hslToRgb(
                (float) $parts[0],
                max(0.0, min(1.0, ((float) rtrim($parts[1], '%')) / 100)),
                max(0.0, min(1.0, ((float) rtrim($parts[2], '%')) / 100)),
            );
        }

        return null;
    }

    /** HSL (h in degrees, s/l in 0–1) to `[r, g, b]` (0–255). */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360.0);
        if ($h < 0) {
            $h += 360.0;
        }
        $h /= 360.0;

        if ($s == 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $hue = static function (float $p, float $q, float $t): float {
                if ($t < 0) {
                    $t += 1;
                }
                if ($t > 1) {
                    $t -= 1;
                }
                if ($t < 1 / 6) {
                    return $p + ($q - $p) * 6 * $t;
                }
                if ($t < 1 / 2) {
                    return $q;
                }
                if ($t < 2 / 3) {
                    return $p + ($q - $p) * (2 / 3 - $t) * 6;
                }
                return $p;
            };
            $r = $hue($p, $q, $h + 1 / 3);
            $g = $hue($p, $q, $h);
            $b = $hue($p, $q, $h - 1 / 3);
        }

        return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
    }

    /** WCAG contrast ratio (1–21) between two `[r, g, b]` colours. */
    private static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** WCAG relative luminance of an `[r, g, b]` colour. */
    private static function luminance(array $rgb): float
    {
        $lin = array_map(static function ($c) {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
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
        // `heading` is a string(255) column; this write has no model validation
        // behind it (unlike a rule), so cap it rather than let a pasted essay
        // throw on strict MySQL/Postgres. `intro` is TEXT and `accent` is already
        // sanitized/capped by sanitizeAccent().
        $heading = $heading !== null ? mb_substr($heading, 0, 255) : null;
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
