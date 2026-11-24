<?php

declare(strict_types=1);

namespace Traqio;

/**
 * What Traqio answered.
 *
 * `trackId` says what happened to the trackID, when the call carried one:
 *   - `matched` — tied to this user (a retry answers `matched` again);
 *   - `unknown` — no click of this product has that id;
 *   - `expired` — the click is older than the attribution window;
 *   - `claimed` — another user, or an app install, already owns that click;
 *   - `refused` — the click failed an anti-fraud rule.
 */
final class Result
{
    public const TRACK_ID_MATCHED = 'matched';
    public const TRACK_ID_UNKNOWN = 'unknown';
    public const TRACK_ID_EXPIRED = 'expired';
    public const TRACK_ID_CLAIMED = 'claimed';
    public const TRACK_ID_REFUSED = 'refused';

    public function __construct(
        /** Events recorded by this call. */
        public readonly int $accepted,
        /** Events Traqio already had (same eventId or same transactionId). */
        public readonly int $duplicates,
        /** What happened to the trackID; null when the call carried none. */
        public readonly ?string $trackId,
        /** Has this user been acquired yet (a sign-up reported, or an app install)? */
        public readonly bool $acquired,
        public readonly Attribution $attribution,
        /** True when the call was dropped because this user asked to be forgotten. */
        public readonly bool $erased = false,
        /**
         * The source Traqio reports this user under, when it holds one: the Traqio link's
         * network when a click won, else the `source` sent (utm_*, click id, referrer).
         *
         * @var array{source: string, medium: string|null, campaign: string|null}|null
         */
        public readonly ?array $source = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['accepted'] ?? 0),
            (int) ($data['duplicates'] ?? 0),
            isset($data['trackId']) ? (string) $data['trackId'] : null,
            (bool) ($data['acquired'] ?? false),
            Attribution::fromArray(is_array($data['attribution'] ?? null) ? $data['attribution'] : []),
            (bool) ($data['erased'] ?? false),
            self::source($data['source'] ?? null),
        );
    }

    /** @return array{source: string, medium: string|null, campaign: string|null}|null */
    private static function source(mixed $source): ?array
    {
        if (!is_array($source) || !is_string($source['source'] ?? null) || $source['source'] === '') {
            return null;
        }

        return [
            'source' => $source['source'],
            'medium' => isset($source['medium']) ? (string) $source['medium'] : null,
            'campaign' => isset($source['campaign']) ? (string) $source['campaign'] : null,
        ];
    }
}
