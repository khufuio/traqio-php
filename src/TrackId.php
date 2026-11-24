<?php

declare(strict_types=1);

namespace Traqio;

/**
 * Reads the trackID — the ad click id the browser SDK (@traqio/web) keeps in a
 * `traq_click` cookie on your own domain, so it arrives with the sign-up request.
 *
 * It parses the raw `Cookie` header on purpose: Laravel encrypts its cookies, and a
 * cookie written by JavaScript fails that decryption, so `$request->cookie()` would
 * return null for it. The header is never touched by that middleware.
 */
final class TrackId
{
    public const COOKIE = 'traq_click';

    /**
     * From a request object — Laravel / Symfony (`$request->headers`), PSR-7
     * (`getHeaderLine`) — or, with no argument, from the current PHP request.
     * Null when the visitor did not come from an ad.
     */
    public static function fromRequest(?object $request = null): ?string
    {
        return self::fromCookieHeader(CookieHeader::of($request));
    }

    /** From a raw `Cookie` header value. */
    public static function fromCookieHeader(?string $header): ?string
    {
        return CookieHeader::value($header, self::COOKIE);
    }
}
