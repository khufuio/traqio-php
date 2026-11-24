<?php

declare(strict_types=1);

namespace Traqio;

/** What `/ingest/analytics/server` answered to capture() / identify(). */
final class AnalyticsResult
{
    public function __construct(
        /** Events (and identify entries) recorded. */
        public readonly int $accepted,
        /**
         * Entries refused, by reason (`invalid_name`, `kind_not_allowed`…).
         *
         * @var array<string, int>
         */
        public readonly array $refused = [],
        /** `recorded`, or `debug` when sent in debug mode (Realtime › Debug only). */
        public readonly string $outcome = 'recorded',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $refused = [];
        if (is_array($data['refused'] ?? null)) {
            foreach ($data['refused'] as $reason => $count) {
                $refused[(string) $reason] = (int) $count;
            }
        }

        return new self((int) ($data['accepted'] ?? 0), $refused, (string) ($data['outcome'] ?? 'recorded'));
    }
}
