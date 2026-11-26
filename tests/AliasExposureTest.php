<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Traqio\Analytics;
use Traqio\Traqio;
use Traqio\TraqioException;

/** alias() and exposure() — identity merge and experiments (HQ cmwv9c16). */
final class AliasExposureTest extends TestCase
{
    private const KEY = 'tq_sk_test_0123456789';
    private const RECORDED = ['ok' => true, 'accepted' => 1, 'refused' => [], 'outcome' => 'recorded'];

    public function testAliasSendsOneAliasEntryUnderTheUser(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $result = $traqio->alias(' u_42 ', ' guest_7 ');

        $this->assertSame(1, $result->accepted);
        $call = $transport->calls[0];
        $this->assertSame('https://api.traqio.app/ingest/analytics/server', $call['url']);
        $body = $call['body'];
        $this->assertSame('u_42', $body['anonymousId']);
        $this->assertSame('u_42', $body['userId']);
        $this->assertCount(1, $body['events']);
        $entry = $body['events'][0];
        $this->assertIsInt($entry['ts']);
        unset($entry['ts']);
        $this->assertSame(['kind' => 'alias', 'userId' => 'u_42', 'previousId' => 'guest_7'], $entry);
    }

    public function testAliasCutsTheSubjectButNotTheUserId(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        // 256 multibyte characters are 256 characters, not 512 bytes.
        $traqio->alias(str_repeat('u', 256), str_repeat('é', 256));

        $body = $transport->calls[0]['body'];
        $this->assertSame(str_repeat('u', 128), $body['anonymousId']);
        $this->assertSame(str_repeat('u', 256), $body['userId']);
        $this->assertSame(str_repeat('é', 256), $body['events'][0]['previousId']);
    }

    public function testAliasRefusesEmptyTooLongOrSameIdsBeforeAnyRequest(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $cases = [
            ['', 'guest', 'userId'],
            ['   ', 'guest', 'userId'],
            ['u_1', '  ', 'previousId'],
            [str_repeat('u', 257), 'guest', '256'],
            ['u_1', str_repeat('p', 257), '256'],
            ['u_1', ' u_1 ', 'differ'],
        ];
        foreach ($cases as [$userId, $previousId, $expected]) {
            try {
                $traqio->alias($userId, $previousId);
                $this->fail("{$userId}/{$previousId} was accepted");
            } catch (TraqioException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->assertCount(0, $transport->calls);
    }

    public function testExposureCapturesDollarExposureWithExperimentAndVariantWinning(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $traqio->exposure(
            ' checkout_v2 ',
            ' treatment ',
            ['experiment' => 'spoofed', 'variant' => 'spoofed', 'surface' => 'web'],
            userId: 'u_1',
            timestamp: 1,
            groups: ['company' => 'acme'],
        );

        $body = $transport->calls[0]['body'];
        $this->assertSame('u_1', $body['anonymousId']);
        $this->assertSame('u_1', $body['userId']);
        $this->assertSame(
            [
                'kind' => 'custom',
                'name' => '$exposure',
                'ts' => 1000,
                'props' => ['experiment' => 'checkout_v2', 'variant' => 'treatment', 'surface' => 'web'],
                'groups' => ['company' => 'acme'],
            ],
            $body['events'][0],
        );
    }

    public function testExposureKeepsExperimentAndVariantWhenTheExtraPropsCannotTravel(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $traqio->exposure('exp', 'a', ['blob' => str_repeat('x', 5000)], distinctId: 'visitor_1');

        $body = $transport->calls[0]['body'];
        $this->assertArrayNotHasKey('userId', $body);
        $this->assertSame(['experiment' => 'exp', 'variant' => 'a'], $body['events'][0]['props']);
    }

    public function testExposureRefusesABadExperimentVariantOrSubjectBeforeAnyRequest(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $cases = [['', 'a'], ['  ', 'a'], [str_repeat('e', 201), 'a'], ['exp', ''], ['exp', str_repeat('v', 201)]];
        foreach ($cases as [$experiment, $variant]) {
            try {
                $traqio->exposure($experiment, $variant, userId: 'u_1');
                $this->fail("{$experiment}/{$variant} was accepted");
            } catch (TraqioException $e) {
                $this->assertStringContainsString('experiment and variant', $e->getMessage());
            }
        }
        try {
            $traqio->exposure('exp', 'a');
            $this->fail('an exposure without a subject was accepted');
        } catch (TraqioException $e) {
            $this->assertStringContainsString('distinctId', $e->getMessage());
        }
        $this->assertCount(0, $transport->calls);
    }

    public function testExposurePropsTakes200CharactersAndIgnoresListProps(): void
    {
        $this->assertSame(
            ['experiment' => str_repeat('é', 200), 'variant' => str_repeat('v', 200)],
            Analytics::exposureProps(str_repeat('é', 200), str_repeat('v', 200)),
        );
        $this->assertSame(['experiment' => 'exp', 'variant' => 'a'], Analytics::exposureProps('exp', 'a', ['x']));
        $this->assertNull(Analytics::exposureProps('exp', 1));
    }
}
