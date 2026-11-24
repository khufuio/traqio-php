<?php

declare(strict_types=1);

namespace Traqio\Laravel;

use Illuminate\Support\ServiceProvider;
use Traqio\Traqio;

/**
 * Auto-discovered by Laravel (composer.json → extra.laravel). Binds one Traqio client,
 * built from config/traqio.php, and lets you publish that file.
 *
 * The client is resolved lazily: an app that has not set TRAQIO_SECRET_KEY yet boots
 * normally, and gets the explanation the first time it actually calls Traqio.
 */
final class TraqioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/traqio.php', 'traqio');

        $this->app->singleton(Traqio::class, static function ($app): Traqio {
            /** @var array{secret_key?: string|null, endpoint?: string, timeout?: float|int, max_retries?: int, debug?: bool|string|null} $config */
            $config = $app['config']->get('traqio', []);

            return new Traqio((string) ($config['secret_key'] ?? ''), [
                'endpoint' => (string) ($config['endpoint'] ?? 'https://api.traqio.app'),
                'timeout' => (float) ($config['timeout'] ?? 10),
                'max_retries' => (int) ($config['max_retries'] ?? 2),
                'debug' => isset($config['debug']) ? filter_var($config['debug'], FILTER_VALIDATE_BOOLEAN) : null,
            ]);
        });
        $this->app->alias(Traqio::class, 'traqio');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/traqio.php' => $this->app->configPath('traqio.php')], 'traqio-config');
        }
    }
}
