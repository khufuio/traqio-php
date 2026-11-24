<?php

declare(strict_types=1);

namespace Traqio\Tests;

use Orchestra\Testbench\TestCase;
use Traqio\Laravel\Facades\Traqio as TraqioFacade;
use Traqio\Laravel\TraqioServiceProvider;
use Traqio\Traqio;
use Traqio\TraqioException;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TraqioServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Traqio' => TraqioFacade::class];
    }

    public function testBindsOneClientFromConfig(): void
    {
        config(['traqio.secret_key' => 'tq_sk_from_env']);
        self::assertSame('https://api.traqio.app', config('traqio.endpoint'));
        $client = $this->app->make(Traqio::class);
        self::assertSame($client, $this->app->make('traqio'));
        self::assertSame($client, TraqioFacade::getFacadeRoot());
    }

    public function testDebugFollowsTheConfig(): void
    {
        config(['traqio.secret_key' => 'tq_sk_from_env', 'traqio.debug' => 'false']);
        self::assertFalse($this->app->make(Traqio::class)->isDebug());
        $this->app->forgetInstance(Traqio::class);
        config(['traqio.debug' => true]);
        self::assertTrue($this->app->make(Traqio::class)->isDebug());
    }

    public function testBootsWithoutAKeyAndExplainsOnFirstUse(): void
    {
        config(['traqio.secret_key' => null]);
        $this->expectException(TraqioException::class);
        $this->expectExceptionMessage('TRAQIO_SECRET_KEY');
        $this->app->make(Traqio::class);
    }

    public function testThePackageIsAutoDiscovered(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertSame([TraqioServiceProvider::class], $composer['extra']['laravel']['providers']);
        self::assertSame(TraqioFacade::class, $composer['extra']['laravel']['aliases']['Traqio']);
    }
}
