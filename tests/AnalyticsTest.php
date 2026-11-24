<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Traqio\Analytics;
use Traqio\AnalyticsResult;
use Traqio\Traqio;
use Traqio\TraqioException;

/** capture() / identify() — /ingest/analytics/server (HQ cmwux580). */
final class AnalyticsTest extends TestCase
{
    private const KEY = 'tq_sk_test_0123456789';
    private const RECORDED = ['ok' => true, 'accepted' => 1, 'refused' => [], 'outcome' => 'recorded'];

    public function testCapturePostsOneCustomEventWithTheKeyInTheHeader(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'endpoint' => 'https://api.example.com/', 'debug' => false]);
        $result = $traqio->capture(' export_finished ', userId: 'u_1', props: ['rows' => 1200], timestamp: 1_700_000_000);

        $this->assertEquals(new AnalyticsResult(1, [], 'recorded'), $result);
        $call = $transport->calls[0];
        $this->assertSame('https://api.example.com/ingest/analytics/server', $call['url']);
        $this->assertSame('Bearer ' . self::KEY, $call['headers']['Authorization']);
        $this->assertSame(
            ['anonymousId' => 'u_1', 'userId' => 'u_1', 'events' => [['kind' => 'custom', 'name' => 'export_finished', 'ts' => 1_700_000_000_000, 'props' => ['rows' => 1200]]]],
            $call['body'],
        );
    }

    public function testAnAnonymousSubjectAndADateTime(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $when = new \DateTimeImmutable('2026-01-02T03:04:05.250Z');
        (new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]))->capture('link_unfurled', distinctId: 'account_42', timestamp: $when);

        $body = $transport->calls[0]['body'];
        $this->assertSame('account_42', $body['anonymousId']);
        $this->assertArrayNotHasKey('userId', $body);
        $this->assertSame($when->getTimestamp() * 1000 + 250, $body['events'][0]['ts']);
        $this->assertArrayNotHasKey('props', $body['events'][0]);
    }

    public function testDropsPropsThatDoNotTravelAndKeepsTheEvent(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        foreach ([['big' => str_repeat('x', 5000)], ['n' => NAN], [1, 2, 3], ['bad' => "\xB1\x31"]] as $props) {
            $traqio->capture('e', distinctId: 'a', props: $props);
        }
        $this->assertCount(4, $transport->calls);
        foreach ($transport->calls as $call) {
            $this->assertArrayNotHasKey('props', $call['body']['events'][0]);
        }
    }

    public function testRefusesBadNamesAndAMissingSubjectBeforeAnyRequest(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        foreach (['$pageview', '  ', str_repeat('x', 129)] as $name) {
            try {
                $traqio->capture($name, distinctId: 'a');
                $this->fail("{$name} was accepted");
            } catch (TraqioException $e) {
                $this->assertStringContainsString('event name', $e->getMessage());
            }
        }
        try {
            $traqio->capture('ok');
            $this->fail('no subject was accepted');
        } catch (TraqioException $e) {
            $this->assertStringContainsString('distinctId', $e->getMessage());
        }
        // 128 multibyte characters are 128 characters, not 256 bytes.
        $traqio->capture('ok', distinctId: str_repeat('é', 128));
        $this->expectExceptionMessage('128');
        try {
            $traqio->capture('ok', distinctId: str_repeat('é', 129));
        } finally {
            $this->assertCount(1, $transport->calls);
        }
    }

    public function testIdentifySendsCleanedTraits(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $traqio->identify('u_1', ['plan' => ' pro ', 'seats' => 12, 'nested' => ['a' => 1], 'bad key' => 1, 'flag' => true], ['signup_platform' => 'web']);

        $body = $transport->calls[0]['body'];
        $this->assertSame('u_1', $body['anonymousId']);
        $this->assertSame('u_1', $body['userId']);
        $entry = $body['events'][0];
        $this->assertSame('identify', $entry['kind']);
        $this->assertSame(['plan' => 'pro', 'seats' => 12, 'flag' => true], $entry['set']);
        $this->assertSame(['signup_platform' => 'web'], $entry['setOnce']);

        $this->expectException(TraqioException::class);
        $traqio->identify('u_1', ['nested' => []]);
    }

    public function testRetriesA5xxNeverA4xxAndDebugMarksTheBatch(): void
    {
        $transport = new FakeTransport(FakeTransport::json(503, []), FakeTransport::json(200, self::RECORDED));
        (new Traqio(self::KEY, ['transport' => $transport, 'max_retries' => 1, 'debug' => false]))->capture('e', distinctId: 'a', timestamp: 1);
        $this->assertCount(2, $transport->calls);
        $this->assertSame($transport->calls[0]['body'], $transport->calls[1]['body']);

        $refused = new FakeTransport(FakeTransport::json(401, ['message' => 'invalid server key']));
        try {
            (new Traqio(self::KEY, ['transport' => $refused, 'debug' => false]))->capture('e', distinctId: 'a');
            $this->fail('a 401 was not raised');
        } catch (TraqioException $e) {
            $this->assertSame(401, $e->status);
        }
        $this->assertCount(1, $refused->calls);

        $debug = new FakeTransport(FakeTransport::json(200, ['accepted' => 1, 'refused' => [], 'outcome' => 'debug']));
        $result = (new Traqio(self::KEY, ['transport' => $debug, 'debug' => true]))->capture('e', distinctId: 'a');
        $this->assertTrue($debug->calls[0]['body']['debug']);
        $this->assertSame('debug', $result->outcome);
    }

    public function testRulesMatchTheServer(): void
    {
        $this->assertSame('a', Analytics::cleanEventName(' a '));
        $this->assertNull(Analytics::cleanEventName('$x'));
        $this->assertSame('$exposure', Analytics::cleanEventName('$exposure'));
        $this->assertNull(Analytics::cleanProps([]));
        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many["k{$i}"] = $i;
        }
        $this->assertCount(50, Analytics::cleanTraits($many));
        $this->assertSame(['t' => str_repeat('é', 200), 'b' => false], Analytics::cleanTraits(['t' => str_repeat('é', 300), 'n' => INF, 'b' => false]));
    }
}
