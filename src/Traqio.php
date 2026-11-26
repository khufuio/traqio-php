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
    public const VERSION = '0.3.0';

    private const DEFAULT_ENDPOINT = 'https://api.traqio.app';
    private const SECRET_PREFIX = 'tq_sk_';
    private const DEV_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    private readonly string $secretKey;
    private readonly string $url;
    private readonly string $analyticsUrl;
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
        $origin = rtrim((string) ($options['endpoint'] ?? self::DEFAULT_ENDPOINT), '/');
        $this->url = $origin . '/ingest/server';
        $this->analyticsUrl = $origin . '/ingest/analytics/server';
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

    /**
     * A product-analytics event from your backend — `export_finished`,
     * `subscription_churned` — read in the console's Analytics section. NOT a
     * conversion: never billed, never sent to an ad network (that is track()).
     *
     *   $traqio->capture('export_finished', userId: (string) $user->id, props: ['rows' => 1200]);
     *
     * `distinctId` is who did it as your backend knows them (an account slug, the
     * visitor id @traqio/web gave you); it defaults to `userId`. `props` must encode to
     * JSON and stay under 4 KB, else they are dropped and the event kept. No screen()
     * on a server, on purpose: the route takes custom events only. `groups` names the
     * accounts it happened in (`['company' => 'acme']`): invalid pairs are dropped, at
     * most 5 types.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $groups
     */
    public function capture(
        string $name,
        ?string $distinctId = null,
        ?string $userId = null,
        array $props = [],
        \DateTimeInterface|int|float|null $timestamp = null,
        array $groups = [],
    ): AnalyticsResult {
        $uid = trim((string) $userId);
        $subject = trim((string) $distinctId);
        if ($subject === '') {
            $subject = $uid;
        }
        if ($subject === '') {
            throw new TraqioException('distinctId (or userId) is required');
        }
        if (Analytics::length($subject) > Analytics::MAX_DISTINCT_ID) {
            throw new TraqioException('distinctId is longer than ' . Analytics::MAX_DISTINCT_ID . ' characters');
        }
        if (Analytics::length($uid) > Analytics::MAX_USER_ID) {
            throw new TraqioException('userId is longer than ' . Analytics::MAX_USER_ID . ' characters');
        }
        $eventName = Analytics::cleanEventName($name);
        if ($eventName === null) {
            throw new TraqioException('The event name must be 1-128 characters and must not start with $');
        }
        $event = ['kind' => 'custom', 'name' => $eventName, 'ts' => self::epochMs($timestamp)];
        $clean = Analytics::cleanProps($props);
        if ($clean !== null) {
            $event['props'] = $clean;
        }
        $cleanGroups = Analytics::cleanGroups($groups);
        if ($cleanGroups !== null) {
            $event['groups'] = $cleanGroups;
        }

        return $this->postAnalytics($subject, $uid !== '' ? $uid : null, [$event]);
    }

    /**
     * A user's PROPERTIES — `plan`, `role`, `seats` — read in the console as a
     * breakdown and a cohort condition. `traits` overwrite, `traitsOnce` are written
     * only where the user has no value yet. Values are text, numbers or booleans —
     * never an email or a name. At most 50 keys.
     *
     * @param array<string, mixed> $traits
     * @param array<string, mixed> $traitsOnce
     */
    public function identify(string $userId, array $traits = [], array $traitsOnce = []): AnalyticsResult
    {
        $uid = trim($userId);
        if ($uid === '') {
            throw new TraqioException('userId is required');
        }
        if (Analytics::length($uid) > Analytics::MAX_USER_ID) {
            throw new TraqioException('userId is longer than ' . Analytics::MAX_USER_ID . ' characters');
        }
        $set = Analytics::cleanTraits($traits);
        $setOnce = Analytics::cleanTraits($traitsOnce);
        if ($set === [] && $setOnce === []) {
            throw new TraqioException('identify needs at least one valid trait');
        }
        $entry = ['kind' => 'identify', 'ts' => self::epochMs(null), 'userId' => $uid];
        if ($set !== []) {
            $entry['set'] = $set;
        }
        if ($setOnce !== []) {
            $entry['setOnce'] = $setOnce;
        }

        return $this->postAnalytics(Analytics::cut($uid, Analytics::MAX_DISTINCT_ID), $uid, [$entry]);
    }

    /**
     * An ACCOUNT's properties — B2B analytics: creates or updates the `company` /
     * `workspace` your users belong to, so the console can count accounts.
     * `groupType` is lowercase (`^[a-z][a-z0-9_]{0,29}$`), `groupKey` your own account
     * id (1–256 characters, never an email). Traits follow the identify rules and may
     * be empty. Events then name their accounts through capture(groups: [...]).
     *
     *   $traqio->group('company', (string) $team->id, ['plan' => 'pro', 'seats' => 12]);
     *
     * @param array<string, mixed> $traits
     * @param array<string, mixed> $traitsOnce
     */
    public function group(string $groupType, string $groupKey, array $traits = [], array $traitsOnce = [], ?string $distinctId = null): AnalyticsResult
    {
        $type = Analytics::cleanGroupType($groupType);
        if ($type === null) {
            throw new TraqioException('groupType must match ^[a-z][a-z0-9_]{0,29}$ (e.g. company)');
        }
        $key = Analytics::cleanGroupKey($groupKey);
        if ($key === null) {
            throw new TraqioException('groupKey must be 1-' . Analytics::MAX_GROUP_KEY . ' characters');
        }
        $subject = trim((string) $distinctId);
        if (Analytics::length($subject) > Analytics::MAX_DISTINCT_ID) {
            throw new TraqioException('distinctId is longer than ' . Analytics::MAX_DISTINCT_ID . ' characters');
        }
        $entry = ['kind' => 'group', 'ts' => self::epochMs(null), 'groupType' => $type, 'groupKey' => $key];
        $set = Analytics::cleanTraits($traits);
        $setOnce = Analytics::cleanTraits($traitsOnce);
        if ($set !== []) {
            $entry['set'] = $set;
        }
        if ($setOnce !== []) {
            $entry['setOnce'] = $setOnce;
        }

        return $this->postAnalytics($subject !== '' ? $subject : Analytics::cut("group:{$type}:{$key}", Analytics::MAX_DISTINCT_ID), null, [$entry]);
    }

    /**
     * The user known so far as `previousId` (a guest id, a pre-sign-up id, an id from
     * an older system) is the same person as `userId`. With the secret key the server
     * merges ALL of `previousId`'s analytics history and properties into `userId`
     * (`userId`'s own properties win). Both ids: 1–256 characters, and different.
     *
     *   $traqio->alias((string) $user->id, $guestId);
     */
    public function alias(string $userId, string $previousId): AnalyticsResult
    {
        $uid = trim($userId);
        $prev = trim($previousId);
        if ($uid === '') {
            throw new TraqioException('userId is required');
        }
        if ($prev === '') {
            throw new TraqioException('previousId is required');
        }
        if (Analytics::length($uid) > Analytics::MAX_USER_ID || Analytics::length($prev) > Analytics::MAX_USER_ID) {
            throw new TraqioException('userId and previousId must be at most ' . Analytics::MAX_USER_ID . ' characters');
        }
        if ($uid === $prev) {
            // The server refuses it (alias_same_id): nothing to merge.
            throw new TraqioException('previousId must differ from userId');
        }
        $entry = ['kind' => 'alias', 'ts' => self::epochMs(null), 'userId' => $uid, 'previousId' => $prev];

        return $this->postAnalytics(Analytics::cut($uid, Analytics::MAX_DISTINCT_ID), $uid, [$entry]);
    }

    /**
     * An experiment exposure: this subject saw `variant` of `experiment` — the
     * `$exposure` event the console's experiment analysis reads. `experiment` and
     * `variant`: 1–200 characters; they win over a prop of the same name. The subject
     * arguments are capture()'s.
     *
     *   $traqio->exposure('checkout_v2', 'treatment', userId: (string) $user->id);
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $groups
     */
    public function exposure(
        string $experiment,
        string $variant,
        array $props = [],
        ?string $distinctId = null,
        ?string $userId = null,
        \DateTimeInterface|int|float|null $timestamp = null,
        array $groups = [],
    ): AnalyticsResult {
        $merged = Analytics::exposureProps($experiment, $variant, $props);
        if ($merged === null) {
            throw new TraqioException('experiment and variant must be 1-' . Analytics::MAX_EXPOSURE_TEXT . ' characters');
        }

        return $this->capture(Analytics::EXPOSURE_EVENT, $distinctId, $userId, $merged, $timestamp, $groups);
    }

    /** @param list<array<string, mixed>> $events */
    private function postAnalytics(string $anonymousId, ?string $userId, array $events): AnalyticsResult
    {
        // The server stamps a backend's rows with its own session (`server`): none sent.
        $payload = ['anonymousId' => $anonymousId];
        if ($userId !== null) {
            $payload['userId'] = $userId;
        }
        $payload['events'] = $events;
        if ($this->debug) {
            $payload['debug'] = true;
        }

        return AnalyticsResult::fromArray($this->request($this->analyticsUrl, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    private static function epochMs(\DateTimeInterface|int|float|null $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return (int) $timestamp->format('Uv');
        }
        if ((is_int($timestamp) || is_float($timestamp)) && is_finite((float) $timestamp)) {
            // Epoch seconds, as PHP's time() and microtime(true) give them.
            return (int) round($timestamp * 1000);
        }

        return (int) round(microtime(true) * 1000);
    }

    private function post(string $body): Result
    {
        return Result::fromArray($this->request($this->url, $body));
    }

    /** @return array<string, mixed> */
    private function request(string $url, string $body): array
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
                $response = $this->transport->post($url, $headers, $body, $this->timeout);
            } catch (TransportException $e) {
                $last = new TraqioException('Traqio API unreachable: ' . $e->getMessage(), null, null, $e);
                continue;
            }
            $json = json_decode($response->body, true);
            if ($response->status >= 200 && $response->status < 300 && is_array($json)) {
                return $json;
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
