<?php

declare(strict_types=1);

namespace Traqio\Http;

/**
 * How a request leaves the process. The default is cURL; pass your own to route
 * through a proxy or an HTTP client you already use.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException on a network error or a timeout (no HTTP answer at all)
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): Response;
}
