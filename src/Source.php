<?php

declare(strict_types=1);

namespace Traqio;

/**
 * Reads where the visitor came from when no Traqio link brought them — what the
 * browser SDK (@traqio/web) keeps in a `traq_src` cookie on your own domain: the
 * landing's `utm_*`, `gclid` / `fbclid` / `ttclid`, the referring site's host.
 *
 * Sent with the sign-up (`signup(..., source: Source::fromRequest($request))`), it is
 * how Traqio reports a user who arrived from an Instagram bio, a newsletter or a
 * Google ad under that source and campaign. It is not an ad attribution: the credit
 * stays organic. A Traqio link click (the trackID) wins over it.
 *
 * The array uses the API's keys: `utmSource`, `utmMedium`, `utmCampaign`,
 * `utmContent`, `utmTerm`, `referrer`, `gclid`, `fbclid`, `ttclid` (strings) and
 * `landedAt` (epoch ms).
 */
final class Source
{
    public const COOKIE = 'traq_src';

    /** Query-string parameter → API field: the only fields Traqio reads in a source. */
    private const FIELDS = [
        'utm_source' => 'utmSource',
        'utm_medium' => 'utmMedium',
        'utm_campaign' => 'utmCampaign',
        'utm_content' => 'utmContent',
        'utm_term' => 'utmTerm',
        'referrer' => 'referrer',
        'gclid' => 'gclid',
        'fbclid' => 'fbclid',
        'ttclid' => 'ttclid',
    ];
    private const MAX_VALUE = 200;

    /**
     * From a request object — Laravel / Symfony (`$request->headers`), PSR-7
     * (`getHeaderLine`) — or, with no argument, from the current PHP request.
     * Null when the visit carried nothing.
     *
     * @return array<string, string|int>|null
     */
    public static function fromRequest(?object $request = null): ?array
    {
        return self::fromCookieHeader(CookieHeader::of($request));
    }

    /**
     * From a raw `Cookie` header value.
     *
     * @return array<string, string|int>|null
     */
    public static function fromCookieHeader(?string $header): ?array
    {
        return self::parse(CookieHeader::value($header, self::COOKIE));
    }

    /**
     * The source in a `traq_src` cookie value (a query string), or null when it holds none.
     *
     * @return array<string, string|int>|null
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Parsed by hand rather than parse_str(), which mangles keys and builds arrays
        // out of `a[]=`. The first occurrence wins, like URLSearchParams.get().
        $params = [];
        foreach (explode('&', $value) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $item] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (!array_key_exists($key, $params)) {
                $params[$key] = urldecode($item);
            }
        }
        $source = [];
        foreach (self::FIELDS as $param => $field) {
            $item = trim($params[$param] ?? '');
            if ($item !== '') {
                $source[$field] = self::cut($item);
            }
        }
        if ($source === []) {
            return null;
        }
        $ts = $params['ts'] ?? '';
        if (is_numeric($ts) && (float) $ts > 0 && is_finite((float) $ts)) {
            $source['landedAt'] = (int) floor((float) $ts);
        }

        return $source;
    }

    /**
     * Only the fields Traqio reads, trimmed; null when none is left.
     *
     * @param array<string, mixed>|null $source
     * @return array<string, string|int>|null
     * @internal
     */
    public static function clean(?array $source): ?array
    {
        if ($source === null) {
            return null;
        }
        $out = [];
        foreach (self::FIELDS as $field) {
            $item = $source[$field] ?? null;
            if (is_string($item) && trim($item) !== '') {
                $out[$field] = self::cut(trim($item));
            }
        }
        if ($out === []) {
            return null;
        }
        $landed = $source['landedAt'] ?? null;
        if ((is_int($landed) || is_float($landed)) && $landed > 0 && is_finite((float) $landed)) {
            $out['landedAt'] = (int) floor((float) $landed);
        }

        return $out;
    }

    /** At most MAX_VALUE characters, never cutting a UTF-8 sequence in half. */
    private static function cut(string $value): string
    {
        return preg_match('/^.{0,' . self::MAX_VALUE . '}/su', $value, $m) === 1 ? $m[0] : substr($value, 0, self::MAX_VALUE);
    }
}
