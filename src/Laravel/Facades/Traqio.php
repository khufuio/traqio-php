<?php

declare(strict_types=1);

namespace Traqio\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Traqio\Result;

/**
 * @method static Result signup(string $userId, ?string $trackId = null, array $events = [])
 * @method static Result track(string $userId, string $name, array $properties = [])
 * @method static Result send(array $input)
 *
 * @see \Traqio\Traqio
 */
final class Traqio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Traqio\Traqio::class;
    }
}
