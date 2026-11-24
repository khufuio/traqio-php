<?php

declare(strict_types=1);

namespace Traqio\Tests;

use Traqio\Http\Response;
use Traqio\Http\Transport;
use Traqio\Http\TransportException;

/** Answers from a list (the last one repeats) and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>, timeout: float}> */
    public array $calls = [];

    /** @var list<Response|TransportException> */
    private array $answers;

    public function __construct(Response|TransportException ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public static function json(int $status, mixed $body): Response
    {
        return new Response($status, (string) json_encode($body));
    }

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): Response
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true), 'timeout' => $timeoutSeconds];
        $next = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }
}
