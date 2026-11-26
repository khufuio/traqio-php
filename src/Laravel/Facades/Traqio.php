<?php

declare(strict_types=1);

namespace Traqio\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Traqio\AnalyticsResult;
use Traqio\Result;

/**
 * @method static Result signup(string $userId, ?string $trackId = null, array $events = [])
 * @method static Result track(string $userId, string $name, array $properties = [])
 * @method static Result send(array $input)
 * @method static AnalyticsResult capture(string $name, ?string $distinctId = null, ?string $userId = null, array $props = [], \DateTimeInterface|int|float|null $timestamp = null, array $groups = [])
 * @method static AnalyticsResult identify(string $userId, array $traits = [], array $traitsOnce = [])
 * @method static AnalyticsResult group(string $groupType, string $groupKey, array $traits = [], array $traitsOnce = [], ?string $distinctId = null)
 * @method static AnalyticsResult alias(string $userId, string $previousId)
 * @method static AnalyticsResult exposure(string $experiment, string $variant, array $props = [], ?string $distinctId = null, ?string $userId = null, \DateTimeInterface|int|float|null $timestamp = null, array $groups = [])
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
