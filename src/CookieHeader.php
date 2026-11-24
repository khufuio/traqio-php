<?php

declare(strict_types=1);

namespace Traqio;

/**
 * Reads one cookie off the raw `Cookie` header — the one TrackId and Source share.
 *
 * It parses the header on purpose: Laravel encrypts its cookies, and a cookie written
 * by JavaScript fails that decryption, so `$request->cookie()` would return null for
 * it. The header is never touched by that middleware.
 *
 * @internal
 */
final class CookieHeader
{
    /**
     * The raw `Cookie` header of a request object — Laravel / Symfony
     * (`$request->headers`), PSR-7 (`getHeaderLine`) — or, with no argument, of the
     * current PHP request. Null when there is none.
     */
    public static function of(?object $request = null): ?string
    {
        if ($request === null) {
            $header = $_SERVER['HTTP_COOKIE'] ?? null;

            return is_string($header) ? $header : null;
        }
        if (method_exists($request, 'getHeaderLine')) {
            return (string) $request->getHeaderLine('Cookie');
        }
        if (property_exists($request, 'headers') && is_object($request->headers) && method_exists($request->headers, 'get')) {
            $header = $request->headers->get('cookie');

            return is_string($header) ? $header : null;
        }

        return null;
    }

    /** The URI-decoded value of the cookie `$name` in a raw header, or null when absent or empty. */
    public static function value(?string $header, string $name): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        foreach (explode(';', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || trim(substr($part, 0, $eq)) !== $name) {
                continue;
            }
            $value = rawurldecode(trim(substr($part, $eq + 1)));

            return $value === '' ? null : $value;
        }

        return null;
    }
}
