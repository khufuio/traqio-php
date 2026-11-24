<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Traqio\Analytics;
use Traqio\Traqio;
use Traqio\TraqioException;

/** group() and capture(groups: ...) — B2B accounts (HQ cmwux5jo). */
final class GroupTest extends TestCase
{
    private const KEY = 'tq_sk_test_0123456789';
    private const RECORDED = ['ok' => true, 'accepted' => 1, 'refused' => [], 'outcome' => 'recorded'];

    public function testCaptureSendsTheCleanedGroupsAndOmitsTheFieldWhenNoneSurvive(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED), FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $traqio->capture('export_finished', distinctId: 'u_1', timestamp: 1, groups: [
            'company' => ' acme ',
            'Workspace' => 'w1',
            'team' => '  ',
            '1bad' => 'x',
            'project' => str_repeat('k', 257),
        ]);
        $this->assertSame(
            ['kind' => 'custom', 'name' => 'export_finished', 'ts' => 1000, 'groups' => ['company' => 'acme']],
            $transport->calls[0]['body']['events'][0],
        );

        $traqio->capture('e', distinctId: 'u_1', groups: ['BAD' => 'x']);
        $this->assertArrayNotHasKey('groups', $transport->calls[1]['body']['events'][0]);
    }

    public function testGroupSendsOneGroupEntryUnderGroupTypeKeyWithoutUserId(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $result = $traqio->group('company', ' acme ', ['plan' => ' pro ', 'seats' => 12, 'nested' => []], ['created_via' => 'api']);

        $this->assertSame(1, $result->accepted);
        $call = $transport->calls[0];
        $this->assertSame('https://api.traqio.app/ingest/analytics/server', $call['url']);
        $body = $call['body'];
        $this->assertSame('group:company:acme', $body['anonymousId']);
        $this->assertArrayNotHasKey('userId', $body);
        $entry = $body['events'][0];
        $this->assertIsInt($entry['ts']);
        unset($entry['ts']);
        $this->assertSame(
            ['kind' => 'group', 'groupType' => 'company', 'groupKey' => 'acme', 'set' => ['plan' => 'pro', 'seats' => 12], 'setOnce' => ['created_via' => 'api']],
            $entry,
        );
    }

    public function testGroupTakesEmptyTraitsAGivenDistinctIdAndCutsTheDefaultSubject(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED), FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $traqio->group('workspace', str_repeat('w', 200));

        $body = $transport->calls[0]['body'];
        $this->assertSame(substr('group:workspace:' . str_repeat('w', 200), 0, 128), $body['anonymousId']);
        $this->assertSame(['kind', 'ts', 'groupType', 'groupKey'], array_keys($body['events'][0]));

        $traqio->group('company', 'acme', distinctId: 'u_1');
        $this->assertSame('u_1', $transport->calls[1]['body']['anonymousId']);
    }

    public function testGroupRefusesABadTypeOrKeyBeforeAnyRequest(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, self::RECORDED));
        $traqio = new Traqio(self::KEY, ['transport' => $transport, 'debug' => false]);
        $cases = [
            ['Company', 'acme', 'groupType'],
            [str_repeat('c', 31), 'acme', 'groupType'],
            ["company\n", 'acme', 'groupType'],
            ['', 'acme', 'groupType'],
            ['company', '   ', 'groupKey'],
            ['company', str_repeat('k', 257), 'groupKey'],
        ];
        foreach ($cases as [$type, $key, $expected]) {
            try {
                $traqio->group($type, $key);
                $this->fail("{$type}/{$key} was accepted");
            } catch (TraqioException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        try {
            $traqio->group('company', 'acme', distinctId: str_repeat('d', 129));
            $this->fail('a 129-character distinctId was accepted');
        } catch (TraqioException $e) {
            $this->assertStringContainsString('128', $e->getMessage());
        }
        $this->assertCount(0, $transport->calls);
    }

    public function testCleanGroupsKeepsAtMostFiveValidTypes(): void
    {
        $this->assertNull(Analytics::cleanGroups(null));
        $this->assertNull(Analytics::cleanGroups(['a']));
        $this->assertNull(Analytics::cleanGroups(['a' => 1]));
        $seven = [];
        for ($i = 0; $i < 7; $i++) {
            $seven["t{$i}"] = "k{$i}";
        }
        $this->assertSame(
            ['t0' => 'k0', 't1' => 'k1', 't2' => 'k2', 't3' => 'k3', 't4' => 'k4'],
            Analytics::cleanGroups(['BAD' => 'x'] + $seven),
        );
        // 256 multibyte characters are 256 characters, not 512 bytes.
        $this->assertSame(str_repeat('é', 256), Analytics::cleanGroupKey(str_repeat('é', 256)));
    }
}
