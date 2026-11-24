<?php

declare(strict_types=1);

namespace Traqio;

use Traqio\Http\CurlTransport;
use Traqio\Http\Transport;
use Traqio\Http\TransportException;

/**
 * The PHP server SDK of Traqio — the server half of the web SDK.
 *
 * The browser half (@traqio/web) keeps the trackID, the ad click id the visitor
 * landed with. The conversion that counts — a sign-up, a purchase — happens on your
 * backend, where the user id is known: this client reports it, with the trackID, and
 * Traqio ties the two together. It authenticates with a SECRET server key, which is
 * what allows a conversion to be tied to a click: keep it on the server.
 */
final class Traqio
{
    public const VERSION = '0.1.0';

    private const DEFAULT_ENDPOINT = 'https://api.traqio.app';
    private const SECRET_PREFIX = 'tq_sk_';
    private const DEV_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    private readonly string $secretKey;
    private readonly string $url;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly Transport $transport;
    private readonly bool $debug;

    /**
     * @param string $secretKey the product's server key (`tq_sk_…`), from Integrations → SDK
     * @param array{endpoint?: string, timeout?: float|int, max_retries?: int, transport?: Transport, debug?: bool|null} $options
     *   `endpoint` default https://api.traqio.app · `timeout` per attempt, seconds, default 10 ·
     *   `max_retries` after a network error / 429 / 5xx, default 2 · `transport` default cURL ·
     *   `debug` flags every call as development traffic, kept out of stats, attribution and
     *   billing; null (default) detects it from TRAQIO_DEBUG, then APP_ENV
     */
    public function __construct(string $secretKey, array $options = [])
    {
        $key = trim($secretKey);
        if ($key === '') {
            throw new TraqioException('The secret key is required — a server key (tq_sk_…) from Integrations → SDK. In Laravel, set TRAQIO_SECRET_KEY.');
        }
        if (!str_starts_with($key, self::SECRET_PREFIX)) {
            throw new TraqioException(str_starts_with($key, 'tq_web_')
                ? 'This is a web key (tq_web_…), made for the browser — the PHP SDK needs a server key (tq_sk_…).'
                : 'The secret key must be a server key (tq_sk_…) from Integrations → SDK.');
        }
        $this->secretKey = $key;
        $this->url = rtrim((string) ($options['endpoint'] ?? self::DEFAULT_ENDPOINT), '/') . '/ingest/server';
        $this->timeout = (float) ($options['timeout'] ?? 10);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->debug = isset($options['debug']) ? (bool) $options['debug'] : self::detectDebug();
    }

    /** Whether this client flags its calls as development traffic. */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Reports a sign-up — the moment this user is acquired — with the trackID they
     * arrived with (see TrackId::fromRequest). Call it once, where you create the account.
     *
     * @param list<array<string, mixed>> $events conversions to record in the same call
     * @param string|null $email never sent: hashed here (self::hashEmail) so a Stripe
     *   payment without metadata.traqio_user_id can still find this user
     * @param array<string, mixed>|null $source where the visitor came from without a Traqio
     *   link — Source::fromRequest($request). Kept once per user (the first one sent); the
     *   trackID wins when both are there
     */
    public function signup(string $userId, ?string $trackId = null, array $events = [], ?string $email = null, ?array $source = null): Result
    {
        $input = ['userId' => $userId, 'trackId' => $trackId, 'signup' => true, 'events' => $events, 'source' => $source];
        if ($email !== null && trim($email) !== '') {
            $input['emailHash'] = self::hashEmail($email);
        }

        return $this->send($input);
    }

    /** SHA-256 hex of the trimmed, lowercased email — the key Traqio matches a Stripe payment on. */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Records one conversion of a known user.
     *
     * @param array{eventId?: string, value?: int|float, currency?: string, transactionId?: string, store?: string, trackId?: string|null} $properties
     *   `eventId` your stable id (default: a UUID minted once per call) · `currency` ISO 4217 ·
     *   `transactionId` the payment's own id (a Stripe invoice `in_…`) · `store` `stripe`, `paddle`… ·
     *   `trackId` when this conversion is the first time you have one for this user
     */
    public function track(string $userId, string $name, array $properties = []): Result
    {
        $trackId = $properties['trackId'] ?? null;
        unset($properties['trackId']);

        return $this->send(['userId' => $userId, 'trackId' => $trackId, 'events' => [['name' => $name] + $properties]]);
    }

    /**
     * The raw call behind signup() and track(), to send several events at once.
     *
     * @param array{userId: string, trackId?: string|null, signup?: bool, emailHash?: string, source?: array<string, mixed>|null, events?: list<array<string, mixed>>} $input
     */
    public function send(array $input): Result
    {
        $userId = trim((string) ($input['userId'] ?? ''));
        if ($userId === '') {
            throw new TraqioException('userId is required');
        }
        $events = [];
        foreach ($input['events'] ?? [] as $event) {
            if (!is_array($event) || trim((string) ($event['name'] ?? '')) === '') {
                throw new TraqioException('every event needs a name');
            }
            $event['eventId'] = (string) ($event['eventId'] ?? self::uuid());
            $events[] = $event;
        }
        $payload = ['appUserId' => $userId];
        $trackId = trim((string) ($input['trackId'] ?? ''));
        if ($trackId !== '') {
            $payload['trackId'] = $trackId;
        }
        if (!empty($input['signup'])) {
            $payload['signup'] = true;
        }
        if (!empty($input['emailHash'])) {
            $payload['emailHash'] = (string) $input['emailHash'];
        }
        $source = Source::clean(is_array($input['source'] ?? null) ? $input['source'] : null);
        if ($source !== null) {
            $payload['source'] = $source;
        }
        if ($events !== []) {
            $payload['events'] = $events;
        }
        // Omitted in production, so a production body stays the same as before.
        if ($this->debug) {
            $payload['debug'] = true;
        }

        // Encoded once: every retry sends the same eventIds, so Traqio keeps one row.
        return $this->post(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function post(string $body): Result
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'traqio-php/' . self::VERSION,
        ];
        $last = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(min(250_000 * 2 ** ($attempt - 1), 4_000_000));
            }
            try {
                $response = $this->transport->post($this->url, $headers, $body, $this->timeout);
            } catch (TransportException $e) {
                $last = new TraqioException('Traqio API unreachable: ' . $e->getMessage(), null, null, $e);
                continue;
            }
            $json = json_decode($response->body, true);
            if ($response->status >= 200 && $response->status < 300 && is_array($json)) {
                return Result::fromArray($json);
            }
            $message = is_array($json) ? ($json['message'] ?? null) : null;
            $last = new TraqioException(
                'Traqio API answered ' . $response->status . ($message !== null ? ': ' . (is_array($message) ? implode(', ', $message) : (string) $message) : ''),
                $response->status,
                $json,
            );
            // A 4xx is the request's fault (bad key, bad payload): sending it again
            // cannot succeed. Only overload (429) and server errors are retried.
            if ($response->status < 500 && $response->status !== 429) {
                throw $last;
            }
        }

        throw $last ?? new TraqioException('Traqio API call failed');
    }

    /**
     * A development process: TRAQIO_DEBUG set to `1` / `true`, or APP_ENV one of
     * local, development, dev, testing. Anything else — APP_ENV unset included — is
     * production.
     */
    private static function detectDebug(): bool
    {
        if (in_array(self::env('TRAQIO_DEBUG'), ['1', 'true'], true)) {
            return true;
        }

        return in_array(self::env('APP_ENV'), self::DEV_ENVIRONMENTS, true);
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? strtolower(trim($value)) : '';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
