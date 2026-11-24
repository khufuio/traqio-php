# traqio/traqio-php

The PHP and Laravel server SDK of [Traqio](https://traqio.app). It reports the
conversions that happen on your **backend** — a sign-up, a subscription, a purchase —
and ties each one to the ad click the visitor came from, **deterministically**.

It is the server half of Traqio's web SDK. The browser half, `@traqio/web`, keeps the
ad click id — the **trackID** — in first-party storage from the landing page to the
sign-up form. Your backend hands that trackID to this package with your own user id,
and Traqio credits the campaign that brought the user. No fingerprinting, no
guesswork, no dependency beyond `ext-curl`. Plain PHP, Laravel (auto-discovered),
Symfony or any PSR-7 framework.

## Install

```bash
composer require traqio/traqio-php
```

PHP 8.1 or later, with `ext-curl` and `ext-json`. On Laravel 12 and 13 the service
provider and the `Traqio` facade are discovered automatically.

## Initialize

**Laravel** — add your server key to `.env`, and that is all:

```dotenv
TRAQIO_SECRET_KEY=tq_sk_...
```

To change the endpoint, the timeout or the retries, publish the config file:
`php artisan vendor:publish --tag=traqio-config`.

**Plain PHP**:

```php
use Traqio\Traqio;

$traqio = new Traqio(getenv('TRAQIO_SECRET_KEY'));
```

**Use a server key** (`tq_sk_…`), created in the Traqio console under
**Integrations → SDK**. It is a secret: keep it in your server's environment, never in
a page or a mobile app. It is what allows a conversion to be tied to a click, which a
public key can never do. A web key (`tq_web_…`) is refused.

## Report the sign-up, with its trackID

Call `signup()` once, where you create the account. `@traqio/web` writes the trackID in
a `traq_click` cookie on your own domain, so it arrives with the sign-up request:

```php
use Traqio\Laravel\Facades\Traqio;
use Traqio\TrackId;

public function store(Request $request)
{
    $user = User::create($request->validated());

    $result = Traqio::signup((string) $user->id, TrackId::fromRequest($request));
    // $result->trackId: "matched" | "unknown" | "expired" | "claimed" | "refused" | null
    // $result->attribution->campaignId, ->network, ->type

    return redirect('/welcome');
}
```

`TrackId::fromRequest()` reads the raw `Cookie` header, on purpose: Laravel encrypts
its cookies, and a cookie written by JavaScript fails that decryption, so
`$request->cookie('traq_click')` returns null for it. Without an argument it reads the
current PHP request; it also accepts a Symfony or PSR-7 request.

The sign-up is the moment the user is **acquired**. Traqio credits the **last click**
before it, inside your attribution window. If your backend is on another domain than
your site, send `Traqio.getTrackId()` from the browser with the form instead.

### Visitors without a Traqio link: their source

A visitor from an Instagram bio, a newsletter or a Google ad with plain
`?utm_source=…&utm_campaign=…` has no trackID. `@traqio/web` keeps their origin — the
`utm_*`, `gclid` / `fbclid` / `ttclid`, the referring site — in a `traq_src` cookie.
Send it with the sign-up and Traqio reports the user under that source and campaign
(`$result->source`, and the **Source / Medium / Campaign (web)** report dimensions). It
is not an ad attribution: the credit stays organic, so no ROAS moves. A trackID, when
there is one, wins.

```php
use Traqio\Source;
use Traqio\TrackId;

$result = Traqio::signup(
    (string) $user->id,
    TrackId::fromRequest($request),
    source: Source::fromRequest($request), // null when the visit carried nothing
);
// $result->source: ['source' => 'instagram', 'medium' => 'social', 'campaign' => 'launch']
```

The first source sent for a user is kept. An `fbclid` / `ttclid` in it also matches the
sign-up forwarded to Meta / TikTok when no trackID click carries one. Like the trackID,
it is read from the raw `Cookie` header (Laravel's cookie decryption would drop it).

## Track conversions

```php
Traqio::track((string) $user->id, 'purchase', [
    'value' => 49,
    'currency' => 'EUR',
    'transactionId' => $paymentIntent->id, // one payment, one row
    'store' => 'stripe',
]);
```

Every event has an `eventId` (a UUID is minted when you pass none), and a payment is
also keyed on its `transactionId`, so a retry or a duplicate report is stored once.
Conversions follow the user: they are credited to the campaign that acquired them.

To keep the call out of the request, run it after the response:
`dispatch(fn () => Traqio::track(...))->afterResponse();`

## Product analytics

`capture()` records a product-analytics event from your backend — what the console's
Analytics section reads (events, funnels, property splits), next to the events your
site and apps send. It is **not** a conversion: it goes to `/ingest/analytics/server`,
is never billed and never reaches an ad network. A purchase or a sign-up stays
`track()` / `signup()`.

```php
$traqio->capture('export_finished', userId: (string) $user->id, props: ['rows' => 1200]);

// A subject that is not a signed-in user: an account, a visitor id @traqio/web gave you.
$traqio->capture('subscription_churned', distinctId: $account->slug);

// A user's properties — a breakdown and a cohort condition in the console.
$traqio->identify((string) $user->id, ['plan' => 'pro', 'seats' => 12], ['signup_source' => 'sales']);

// Laravel
Traqio::capture('export_finished', userId: (string) $user->id);
```

One request per call, retried like the others (in Laravel, a queued job keeps it off
the request). Names are 1-128 characters and never start with `$` (reserved); `props`
must be an associative array that encodes to JSON under 4 KB, else they are dropped
and the event kept; trait values are text, numbers or booleans — never an email or a
name. There is no `screen()` on a server: the route takes custom events only. In
debug mode the events go to Analytics › Realtime › Debug and count nowhere.

## Groups (B2B accounts)

In B2B, your customer is an account — a company, a workspace — as much as a person.
`group()` creates or updates that account with its properties; `capture()` then names,
through `groups:`, the account each event happened in. The console's Analytics can then
count accounts instead of people: funnels and retention by account, unique accounts,
and the list of your accounts with their properties.

```php
// After sign-in, or when the account changes (a plan change in a billing webhook):
// create or update the account. Traits may be empty — the account is created anyway.
$traqio->group('company', (string) $team->id, ['plan' => 'pro', 'seats' => 12]);

// Every event that happens in that account names it.
$traqio->capture('export_finished', userId: (string) $user->id, groups: ['company' => (string) $team->id]);
```

The group type is lowercase (`company`, `workspace`, `team`: a letter, then letters,
digits or `_`, 30 characters at most); the key is your own account id, 1-256
characters — never an email. A bad type or key throws a `TraqioException` before any request; in
`capture()`, invalid pairs are dropped and at most 5 types are kept. Traits follow the
identify rules — never an email or a person's name. A server keeps nothing between
calls, so pass the groups on each event that belongs to an account; `resetGroups()` on
sign-out (or `reset()`) belongs to the web and app SDKs, which remember the account.

## What it does today

- Ties a sign-up to its click and answers with the credited campaign — **yes**.
- Records conversions with value, currency and payment id, deduplicated — **yes**.
- Leaves your mobile apps' installs, revenue and ROAS untouched: a conversion sent
  here is never attached to an app install — **yes**.
- Shows web conversions in the console's reports — **not yet**; the API response is
  where you read the attribution today.
- Sends web conversions to Meta or TikTok — **not yet**.

## Errors and retries

A network error, a timeout, a `429` or a `5xx` is retried (twice by default) with the
same body, so nothing is counted twice. A `4xx` — a wrong key, an invalid payload — is
not retried. Both end as a `Traqio\TraqioException` with `->status` and `->body`:

```php
use Traqio\TraqioException;

try {
    Traqio::signup((string) $user->id, $trackId);
} catch (TraqioException $e) {
    report($e); // never let it fail the sign-up itself
}
```

## Options

| Laravel (`config/traqio.php`) | Plain PHP option | Default | |
| --- | --- | --- | --- |
| `secret_key` (`TRAQIO_SECRET_KEY`) | constructor argument | — | Your server key, `tq_sk_…` |
| `endpoint` (`TRAQIO_ENDPOINT`) | `endpoint` | `https://api.traqio.app` | Origin of the Traqio API |
| `timeout` (`TRAQIO_TIMEOUT`) | `timeout` | `10` | Seconds per attempt |
| `max_retries` (`TRAQIO_MAX_RETRIES`) | `max_retries` | `2` | Retries after a network error, a 429 or a 5xx |
| — | `transport` | cURL | Your own `Traqio\Http\Transport` (proxy, HTTP client) |
| `debug` (`TRAQIO_DEBUG`) | `debug` | auto | `true` / `false` forces debug mode on or off — see below |

## Debug mode

Calls from a development machine are flagged as debug traffic. Without a `debug`
option, the client turns it on by itself when `TRAQIO_DEBUG` is `1` or `true`, or
when `APP_ENV` is `local`, `development`, `dev` or `testing`; anything else — `APP_ENV`
unset included — is production. In Laravel, `traqio.debug` defaults to `TRAQIO_DEBUG`,
else to the `local` and `testing` environments. `'debug' => true` or `false` overrides
the detection, and `$traqio->isDebug()` tells which mode is on.

Debug traffic shows in the console's **Debug** view, but it is never counted,
attributed, forwarded to ad networks or billed.

## Requirements

PHP 8.1+ with `ext-curl` and `ext-json`; Laravel 12 or 13 for the auto-discovered
integration. A Traqio product with a server key, and `@traqio/web` on the site so
the trackID is kept until sign-up.

## Documentation

Full guide, the trackID and how web attribution works:
https://traqio.app/docs/sdks/php

## License

MIT
