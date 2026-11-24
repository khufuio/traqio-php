<?php

declare(strict_types=1);

namespace Traqio;

/**
 * Who gets the credit for a user: the last click before their sign-up, inside the
 * attribution window — or `organic`.
 */
final class Attribution
{
    public function __construct(
        /** `deterministic` when a click won, `organic` otherwise. */
        public readonly string $type,
        /** `meta`, `tiktok`, `google`… or `organic`. */
        public readonly string $network,
        public readonly ?string $campaignId,
        public readonly ?string $adsetId,
        public readonly ?string $adId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? 'organic'),
            (string) ($data['network'] ?? 'organic'),
            isset($data['campaignId']) ? (string) $data['campaignId'] : null,
            isset($data['adsetId']) ? (string) $data['adsetId'] : null,
            isset($data['adId']) ? (string) $data['adId'] : null,
        );
    }
}
