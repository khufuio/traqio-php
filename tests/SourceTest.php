<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Traqio\Source;
use Traqio\Traqio;

/** The visit's source (utm_*, click ids, referrer — no Traqio link), as @traqio/node 0.4.0. */
final class SourceTest extends TestCase
{
    private const KEY = 'tq_sk_test_0123456789';

    // What @traqio/web writes: a query string, URI-encoded once more as a cookie value.
    private const KEPT = 'utm_source=instagram&utm_medium=social&utm_campaign=Launch+Nov&referrer=l.instagram.com&fbclid=IwAR0&ts=1731577200000';

    private const EXPECTED = [
        'utmSource' => 'instagram',
        'utmMedium' => 'social',
        'utmCampaign' => 'Launch Nov',
        'referrer' => 'l.instagram.com',
        'fbclid' => 'IwAR0',
        'landedAt' => 1731577200000,
    ];

    private const OK = [
        'accepted' => 0,
        'duplicates' => 0,
        'acquired' => true,
        'attribution' => ['type' => 'organic', 'network' => 'organic', 'campaignId' => null, 'adsetId' => null, 'adId' => null],
    ];

    private static function header(): string
    {
        return '_ga=GA1.1; traq_src=' . rawurlencode(self::KEPT) . '; traq_click=ck-1';
    }

    private function client(FakeTransport $transport): Traqio
    {
        return new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
    }

    public function testReadsTheCookieOffAHeaderTheCurrentRequestLaravelAndPsr7(): void
    {
        self::assertSame('traq_src', Source::COOKIE);
        self::assertSame(self::EXPECTED, Source::fromCookieHeader(self::header()));

        // Laravel's EncryptCookies leaves null in the bag for a JS cookie; the header still holds it.
        $laravel = Request::create('/signup', 'POST', [], ['traq_src' => null], [], ['HTTP_COOKIE' => self::header()]);
        self::assertSame(self::EXPECTED, Source::fromRequest($laravel));

        $psr7 = new class (self::header()) {
            public function __construct(private readonly string $cookie)
            {
            }

            public function getHeaderLine(string $name): string
            {
                return $name === 'Cookie' ? $this->cookie : '';
            }
        };
        self::assertSame(self::EXPECTED, Source::fromRequest($psr7));

        $_SERVER['HTTP_COOKIE'] = self::header();
        try {
            self::assertSame(self::EXPECTED, Source::fromRequest());
        } finally {
            unset($_SERVER['HTTP_COOKIE']);
        }
    }

    public function testAnswersNullWhenThereIsNoSourceOrNothingItKnows(): void
    {
        self::assertNull(Source::fromCookieHeader('_ga=1'));
        self::assertNull(Source::fromCookieHeader(null));
        self::assertNull(Source::parse('foo=bar&ts=12'));
        self::assertNull(Source::parse(''));
        self::assertNull(Source::fromRequest(new \stdClass()));
        self::assertSame(['gclid' => 'Cj0'], Source::parse('gclid=Cj0&ts=nope'));
        self::assertSame(['utmSource' => 'a'], Source::parse('utm_source=a&utm_source=b&utm_medium[]=x'));
    }

    public function testSignupSendsItNextToTheTrackIdTrimmedToTheFieldsTraqioReads(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $source = (Source::fromCookieHeader(self::header()) ?? []) + ['utmTerm' => '  ', 'password' => 'x'];
        $this->client($t)->signup('u1', 'ck-1', source: $source);

        self::assertSame(['appUserId' => 'u1', 'trackId' => 'ck-1', 'signup' => true, 'source' => self::EXPECTED], $t->calls[0]['body']);
    }

    public function testSendsNoSourceKeyWhenThereIsNone(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t)->signup('u1', source: Source::fromCookieHeader('_ga=1'));
        $this->client($t)->signup('u1', source: []);

        self::assertSame([['appUserId' => 'u1', 'signup' => true], ['appUserId' => 'u1', 'signup' => true]], array_column($t->calls, 'body'));
    }

    public function testTheAnswerCarriesTheSourceTheUserIsReportedUnder(): void
    {
        $reported = ['source' => 'instagram', 'medium' => 'social', 'campaign' => 'Launch Nov'];
        $t = new FakeTransport(FakeTransport::json(201, self::OK + ['source' => $reported]), FakeTransport::json(201, self::OK));

        self::assertSame($reported, $this->client($t)->signup('u1', source: self::EXPECTED)->source);
        self::assertNull($this->client($t)->signup('u2')->source);
    }

    public function testCutsALongValueWithoutBreakingUtf8(): void
    {
        $long = str_repeat('é', 250);
        $parsed = Source::parse('utm_campaign=' . rawurlencode($long));

        self::assertSame(str_repeat('é', 200), $parsed['utmCampaign'] ?? null);
    }
}
