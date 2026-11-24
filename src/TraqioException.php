<?php

declare(strict_types=1);

namespace Traqio;

/**
 * A refused call (4xx, never retried) or one that kept failing (5xx, 429, network).
 */
final class TraqioException extends \RuntimeException
{
    /**
     * @param int|null $status the HTTP status, or null for a network error or a timeout
     * @param mixed $body the API's decoded JSON answer, when there was one
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
