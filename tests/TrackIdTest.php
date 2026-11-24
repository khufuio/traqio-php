<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Traqio\TrackId;

final class TrackIdTest extends TestCase
{
    public function testFindsTheCookieTheBrowserSdkWrites(): void
    {
        self::assertSame('traq_click', TrackId::COOKIE);
        self::assertSame('ck-9', TrackId::fromCookieHeader('a=1; traq_click=ck-9; b=2'));
        self::assertSame('ck-9', TrackId::fromCookieHeader('traq_click=ck%2D9'));
        self::assertNull(TrackId::fromCookieHeader('xtraq_click=nope; a=1'));
        self::assertNull(TrackId::fromCookieHeader('traq_click='));
        self::assertNull(TrackId::fromCookieHeader(null));
    }

    public function testReadsTheCurrentPhpRequest(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'session=x; traq_click=ck-1';
        try {
            self::assertSame('ck-1', TrackId::fromRequest());
        } finally {
            unset($_SERVER['HTTP_COOKIE']);
        }
        self::assertNull(TrackId::fromRequest());
    }

    public function testReadsALaravelOrSymfonyRequestHeaderNotItsDecryptedCookieBag(): void
    {
        // What Laravel's EncryptCookies leaves behind for a cookie written by JavaScript:
        // the bag holds null (decryption failed), the raw header still holds the value.
        $request = Request::create('/signup', 'POST', [], ['traq_click' => null], [], ['HTTP_COOKIE' => 'traq_click=ck-2']);
        self::assertNull($request->cookies->get('traq_click'));
        self::assertSame('ck-2', TrackId::fromRequest($request));
    }

    public function testReadsAPsr7Request(): void
    {
        $psr7 = new class () {
            public function getHeaderLine(string $name): string
            {
                return $name === 'Cookie' ? 'traq_click=ck-3' : '';
            }
        };
        self::assertSame('ck-3', TrackId::fromRequest($psr7));
        self::assertNull(TrackId::fromRequest(new \stdClass()));
    }
}
