<?php

declare(strict_types=1);

namespace Traqio\Tests;

use PHPUnit\Framework\TestCase;
use Traqio\Http\TransportException;
use Traqio\Result;
use Traqio\Traqio;
use Traqio\TraqioException;

final class TraqioTest extends TestCase
{
    private const KEY = 'tq_sk_test_0123456789';

    private const OK = [
        'ok' => true,
        'accepted' => 1,
        'duplicates' => 0,
        'trackId' => 'matched',
        'acquired' => true,
        'attribution' => ['type' => 'deterministic', 'network' => 'meta', 'campaignId' => 'c1', 'adsetId' => null, 'adId' => null],
    ];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // A production process unless a test says otherwise, whatever the runner sets.
        foreach (['TRAQIO_DEBUG', 'APP_ENV'] as $name) {
            $this->savedEnv[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $this->setEnv($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$env, $server, $process]) {
            $this->setEnv($name, null);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    private function client(FakeTransport $transport, array $options = []): Traqio
    {
        return new Traqio(self::KEY, ['transport' => $transport] + $options);
    }

    public function testAcceptsOnlyAServerKeyAndSaysWhy(): void
    {
        foreach (['' => 'required', 'tq_web_abc' => 'web key', 'sk_live_stripe' => 'server key'] as $key => $why) {
            try {
                new Traqio($key);
                self::fail("accepted {$key}");
            } catch (TraqioException $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
        self::assertInstanceOf(Traqio::class, new Traqio(self::KEY));
    }

    public function testTheKeyIsABearerHeaderNeverInTheBody(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t)->signup('u1');
        self::assertSame('Bearer ' . self::KEY, $t->calls[0]['headers']['Authorization']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($t->calls[0]['body']));
    }

    public function testSignupPostsTheUserTheTrackIdAndTheFlag(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $result = $this->client($t, ['endpoint' => 'https://api.example.test/'])->signup('u1', ' ck-1 ');

        self::assertSame('https://api.example.test/ingest/server', $t->calls[0]['url']);
        self::assertSame(['appUserId' => 'u1', 'trackId' => 'ck-1', 'signup' => true], $t->calls[0]['body']);
        self::assertSame(Result::TRACK_ID_MATCHED, $result->trackId);
        self::assertTrue($result->acquired);
        self::assertSame('meta', $result->attribution->network);
        self::assertSame('c1', $result->attribution->campaignId);
        self::assertFalse($result->erased);
    }

    public function testSignupSendsOnlyTheHashOfTheEmail(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t)->signup('u1', null, [], '  Ada@Example.COM ');
        self::assertSame(['appUserId' => 'u1', 'signup' => true, 'emailHash' => 'b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'], $t->calls[0]['body']);
    }

    public function testASignupWithoutTrackIdSendsNone(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, ['trackId' => null] + self::OK));
        $result = $this->client($t)->signup('u1', null);
        self::assertSame(['appUserId' => 'u1', 'signup' => true], $t->calls[0]['body']);
        self::assertNull($result->trackId);
    }

    public function testProductionByDefaultWithoutTheDebugKey(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $client = $this->client($t);
        $client->signup('u1');
        self::assertFalse($client->isDebug());
        self::assertArrayNotHasKey('debug', $t->calls[0]['body']);
    }

    public function testDebugTrueFlagsEveryCall(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $client = $this->client($t, ['debug' => true]);
        $client->signup('u1');
        $client->track('u1', 'purchase', ['value' => 1]);
        self::assertTrue($client->isDebug());
        self::assertSame([true, true], array_column(array_column($t->calls, 'body'), 'debug'));
    }

    public function testTraqioDebugEnvIsDetected(): void
    {
        foreach (['1' => true, 'true' => true, '0' => false] as $value => $debug) {
            $this->setEnv('TRAQIO_DEBUG', (string) $value);
            self::assertSame($debug, $this->client(new FakeTransport(FakeTransport::json(201, self::OK)))->isDebug(), "TRAQIO_DEBUG={$value}");
        }
    }

    public function testADevelopmentAppEnvIsDetected(): void
    {
        foreach (['local' => true, 'development' => true, 'dev' => true, 'testing' => true, 'production' => false, 'staging' => false] as $env => $debug) {
            $this->setEnv('APP_ENV', $env);
            self::assertSame($debug, $this->client(new FakeTransport(FakeTransport::json(201, self::OK)))->isDebug(), "APP_ENV={$env}");
        }
    }

    public function testExplicitFalseBeatsTheEnv(): void
    {
        $this->setEnv('TRAQIO_DEBUG', '1');
        $this->setEnv('APP_ENV', 'local');
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t, ['debug' => false])->signup('u1');
        self::assertArrayNotHasKey('debug', $t->calls[0]['body']);
    }

    public function testTrackSendsOneEventWithAnEventIdMintedOnce(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t)->track('u1', 'purchase', ['value' => 49, 'currency' => 'EUR', 'transactionId' => 'pi_1', 'store' => 'stripe']);
        $body = $t->calls[0]['body'];
        self::assertSame('u1', $body['appUserId']);
        self::assertArrayNotHasKey('signup', $body);
        self::assertCount(1, $body['events']);
        self::assertSame(['name' => 'purchase', 'value' => 49, 'currency' => 'EUR', 'transactionId' => 'pi_1', 'store' => 'stripe'], array_diff_key($body['events'][0], ['eventId' => 1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['events'][0]['eventId']);
    }

    public function testTrackKeepsACallersEventIdAndCarriesATrackId(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $this->client($t)->track('u1', 'trial', ['eventId' => 'evt-42', 'trackId' => 'ck-7']);
        self::assertSame('evt-42', $t->calls[0]['body']['events'][0]['eventId']);
        self::assertSame('ck-7', $t->calls[0]['body']['trackId']);
        self::assertArrayNotHasKey('trackId', $t->calls[0]['body']['events'][0]);
    }

    public function testRefusesAMissingUserOrANamelessEventBeforeAnyRequest(): void
    {
        $t = new FakeTransport(FakeTransport::json(201, self::OK));
        $client = $this->client($t);
        foreach ([fn () => $client->signup(' '), fn () => $client->send(['userId' => 'u1', 'events' => [['name' => '']]])] as $call) {
            try {
                $call();
                self::fail('no exception');
            } catch (TraqioException) {
            }
        }
        self::assertCount(0, $t->calls);
    }

    public function testRetriesA5xxAndANetworkErrorWithTheSameBody(): void
    {
        $t = new FakeTransport(FakeTransport::json(503, ['message' => 'busy']), new TransportException('connection reset'), FakeTransport::json(201, self::OK));
        $result = $this->client($t, ['max_retries' => 2])->track('u1', 'purchase', ['value' => 1]);
        self::assertSame(1, $result->accepted);
        self::assertCount(3, $t->calls);
        self::assertCount(1, array_unique(array_map(static fn ($c) => json_encode($c['body']), $t->calls)));
    }

    public function testNeverRetriesA4xxAndSurfacesTheApiMessage(): void
    {
        $t = new FakeTransport(FakeTransport::json(401, ['message' => 'a server key is required']));
        try {
            $this->client($t, ['max_retries' => 3])->signup('u1');
            self::fail('no exception');
        } catch (TraqioException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('a server key is required', $e->getMessage());
        }
        self::assertCount(1, $t->calls);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $t = new FakeTransport(new TransportException('timed out'));
        try {
            $this->client($t, ['max_retries' => 1])->signup('u1');
            self::fail('no exception');
        } catch (TraqioException $e) {
            self::assertNull($e->status);
            self::assertStringContainsString('unreachable: timed out', $e->getMessage());
        }
        self::assertCount(2, $t->calls);
    }
}
