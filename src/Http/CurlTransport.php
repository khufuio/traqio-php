<?php

declare(strict_types=1);

namespace Traqio\Http;

/**
 * The default transport. The handle is freed when it goes out of scope (PHP 8):
 * `curl_close()` is a no-op there, and deprecated in PHP 8.5.
 */
final class CurlTransport implements Transport
{
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): Response
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('cURL could not be initialised');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeoutSeconds, 5.0) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $answer = curl_exec($handle);
        if ($answer === false) {
            $error = curl_error($handle);
            throw new TransportException($error !== '' ? $error : 'request failed');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, (string) $answer);
    }
}
