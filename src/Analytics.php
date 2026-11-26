<?php

declare(strict_types=1);

namespace Traqio;

/**
 * The product-analytics rules the server applies (apps/api/src/product-analytics/
 * screen.ts, identify.ts), applied here first so a bad value never travels.
 */
final class Analytics
{
    public const MAX_EVENT_NAME = 128;
    public const MAX_PROPS_BYTES = 4096;
    public const MAX_DISTINCT_ID = 128;
    public const MAX_USER_ID = 256;
    public const MAX_TRAITS = 50;
    private const MAX_TRAIT_TEXT = 200;
    private const TRAIT_KEY = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/';
    public const MAX_GROUP_KEY = 256;
    public const MAX_GROUPS = 5;
    // `D`: `$` must not match before a trailing newline.
    private const GROUP_TYPE = '/^[a-z][a-z0-9_]{0,29}$/D';
    public const EXPOSURE_EVENT = '$exposure';
    public const MAX_EXPOSURE_TEXT = 200;

    /** A custom event name the server takes, or null: non-empty, ≤ 128, never `$…` (reserved). */
    public static function cleanEventName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $n = trim($name);

        return $n !== '' && self::length($n) <= self::MAX_EVENT_NAME && (!str_starts_with($n, '$') || $n === '$exposure') ? $n : null;
    }

    /**
     * Properties as they can travel: an associative array that encodes to JSON, under
     * 4 KB — else null (the event is kept without them). A list is not an object.
     *
     * @return array<string, mixed>|null
     */
    public static function cleanProps(mixed $props): ?array
    {
        if (!is_array($props) || $props === [] || array_is_list($props)) {
            return null;
        }
        try {
            $json = json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }

        return strlen($json) <= self::MAX_PROPS_BYTES ? $props : null;
    }

    /**
     * Traits as the server keeps them: valid keys; text (trimmed, cut at 200), finite
     * numbers, booleans — anything else dropped; at most 50.
     *
     * @return array<string, string|int|float|bool>
     */
    public static function cleanTraits(mixed $traits): array
    {
        $out = [];
        if (!is_array($traits)) {
            return $out;
        }
        foreach ($traits as $key => $raw) {
            if (count($out) >= self::MAX_TRAITS) {
                break;
            }
            if (!is_string($key) || preg_match(self::TRAIT_KEY, $key) !== 1) {
                continue;
            }
            if (is_bool($raw) || is_int($raw)) {
                $out[$key] = $raw;
            } elseif (is_float($raw) && is_finite($raw)) {
                $out[$key] = $raw;
            } elseif (is_string($raw) && trim($raw) !== '') {
                $out[$key] = self::cut(trim($raw), self::MAX_TRAIT_TEXT);
            }
        }

        return $out;
    }

    /** A group type the server takes (`company`, `workspace`…), or null. */
    public static function cleanGroupType(mixed $type): ?string
    {
        return is_string($type) && preg_match(self::GROUP_TYPE, $type) === 1 ? $type : null;
    }

    /** A group key the server takes: trimmed, 1–256 characters — or null. */
    public static function cleanGroupKey(mixed $key): ?string
    {
        if (!is_string($key)) {
            return null;
        }
        $k = trim($key);

        return $k !== '' && self::length($k) <= self::MAX_GROUP_KEY ? $k : null;
    }

    /**
     * An event's `groups` as they can travel: valid `type => key` pairs only, at most
     * 5 types — else null.
     *
     * @return array<string, string>|null
     */
    public static function cleanGroups(mixed $groups): ?array
    {
        if (!is_array($groups)) {
            return null;
        }
        $out = [];
        foreach ($groups as $rawType => $rawKey) {
            if (count($out) >= self::MAX_GROUPS) {
                break;
            }
            $type = self::cleanGroupType($rawType);
            $key = self::cleanGroupKey($rawKey);
            if ($type !== null && $key !== null) {
                $out[$type] = $key;
            }
        }

        return $out !== [] ? $out : null;
    }

    /**
     * `$exposure`'s properties: `[...props, experiment, variant]` — the two named ones
     * win over a prop of the same name — or null when the experiment or the variant is
     * not text of 1–200 characters once trimmed.
     *
     * @return array<string, mixed>|null
     */
    public static function exposureProps(mixed $experiment, mixed $variant, mixed $props = []): ?array
    {
        $e = is_string($experiment) ? trim($experiment) : '';
        $v = is_string($variant) ? trim($variant) : '';
        if ($e === '' || $v === '' || self::length($e) > self::MAX_EXPOSURE_TEXT || self::length($v) > self::MAX_EXPOSURE_TEXT) {
            return null;
        }
        $base = is_array($props) && !array_is_list($props) ? $props : [];
        $merged = array_merge($base, ['experiment' => $e, 'variant' => $v]);

        // Props that cannot travel (over 4 KB, not JSON) would take experiment/variant
        // down with them in capture(): keep the exposure, drop the extras.
        return self::cleanProps($merged) !== null ? $merged : ['experiment' => $e, 'variant' => $v];
    }

    /** Characters (not bytes) in a UTF-8 string — without requiring ext-mbstring. */
    public static function length(string $s): int
    {
        return (int) preg_match_all('/./su', $s);
    }

    /** The first `$max` characters of a UTF-8 string — without requiring ext-mbstring. */
    public static function cut(string $s, int $max): string
    {
        return preg_match('/^.{0,' . $max . '}/su', $s, $m) === 1 ? $m[0] : $s;
    }
}
