# fraudnova-php

[FraudNova](https://fraudnova.com) is a fraud-detection API. It returns a fraud score and risk flags for any IP address, email address or phone number, so your application can decide whether to accept, review or block a signup, lead or order. This is the official PHP client.

[![CI](https://github.com/dahleedam/fraudnova-php/actions/workflows/ci.yml/badge.svg)](https://github.com/dahleedam/fraudnova-php/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/fraudnova/fraudnova-php)](https://packagist.org/packages/fraudnova/fraudnova-php)
[![PHP version](https://img.shields.io/packagist/dependency-v/fraudnova/fraudnova-php/php)](https://packagist.org/packages/fraudnova/fraudnova-php)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

## Requirements

- PHP 8.2 or later
- Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client, for example Guzzle or the Symfony HTTP client

## Installation

```bash
composer require fraudnova/fraudnova-php guzzlehttp/guzzle
```

Guzzle or the Symfony HTTP client is detected automatically, and you can inject any other PSR-18 client yourself (see [Bring your own HTTP client](#bring-your-own-http-client)).

You need an API key. [Register](https://fraudnova.com/register) to get free trial credits, then create a key at [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys). The key starts with `fn_` and is shown once, so copy it right away.

## Quickstart

```php
require __DIR__.'/vendor/autoload.php';
$client = new FraudNova\Client('fn_your_api_key');
$result = $client->ip('8.8.8.8');
if ($result->flagged()) { /* review or block */ }
echo $result->fraudScore;
```

## Checking IP addresses, emails and phone numbers

```php
use FraudNova\Client;
use FraudNova\IpOptions;

$client = new Client('fn_your_api_key');

$ip = $client->ip('8.8.8.8');
$email = $client->email('someone@example.com');
$phone = $client->phone('+1 817 679 1808');
$phone = $client->phone('8176791808', 'US'); // optional two-letter country hint
```

IP checks take an optional `IpOptions`. Every field is optional; leave it out to use the API default.

```php
use FraudNova\IpOptions;

$options = new IpOptions(
    strictness: 1,                                 // 0 = lenient, 3 = strict
    mobile: false,                                 // the request comes from a mobile device
    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null, // enables the device and browser fields
    userLanguage: $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
);

$result = $client->ip($visitorIp, $options);
```

An empty value, or a `strictness` outside 0 to 3, throws `FraudNova\Exception\InvalidArgumentException` before any request is sent.

## Batch checks

`batchIp()`, `batchEmail()` and `batchPhone()` score up to `Client::BATCH_MAX` (100) values in one request. A larger list throws `InvalidArgumentException`, so split it with `array_chunk`:

```php
use FraudNova\Client;

foreach (array_chunk($ips, Client::BATCH_MAX) as $chunk) {
    $batch = $client->batchIp($chunk);

    foreach ($batch->successful() as $item) {
        echo $item->subject, ': ', $item->result?->fraudScore, PHP_EOL;
    }

    foreach ($batch->failed() as $item) {
        echo $item->subject, ' failed: ', $item->error, PHP_EOL;
    }

    echo 'Credits left: ', $batch->creditsRemaining, PHP_EOL;
}
```

`batchIp()` takes the same `IpOptions` as `ip()`, and `batchPhone()` takes one country hint for the whole batch. `BatchResult` is countable and iterable; each `BatchItem` has `subject`, `success`, `result` (an `IpResult`, `EmailResult` or `PhoneResult`, or `null` on failure) and `error`. `hasFailures()` tells you whether any item failed.

One bad value never fails the batch. A failed item carries one of these error codes:

| `error` | Helper | Meaning |
| --- | --- | --- |
| `invalid_ip`, `invalid_email`, `invalid_phone` | `isInvalid()` | The value is not a valid IP address, email address or phone number. |
| `insufficient_credits` | `isInsufficientCredits()` | Your balance ran out partway through the batch. |
| `upstream_failed` | `isUpstreamFailed()` | The lookup failed. The item was not charged. |

## Results

`ip()`, `email()` and `phone()` return an `IpResult`, `EmailResult` or `PhoneResult`. Each has the API's fields as camelCase properties, for example `fraudScore`, `recentAbuse` and `countryCode`. `fraudScore` is always an integer from 0 to 100; every other field is nullable, because the API does not return every field for every check.

```php
$ip = $client->ip('8.8.8.8');
$ip->proxy;        // ?bool
$ip->vpn;          // ?bool
$ip->countryCode;  // ?string, for example 'US'
$ip->isp;          // ?string (API field ISP)
$ip->asn;          // ?int (API field ASN)

$email = $client->email('someone@example.com');
$email->disposable;     // ?bool
$email->deliverability; // ?string

$phone = $client->phone('+18176791808');
$phone->voip;     // ?bool (API field VOIP)
$phone->lineType; // ?string
$phone->carrier;  // ?string
```

`toArray()` returns the full API response for that check, with the original field names, including any field the client does not map to a property:

```php
$fields = $client->ip('8.8.8.8')->toArray(); // ['fraud_score' => 20, 'proxy' => false, 'ISP' => 'Google', ...]
```

`creditsRemaining` holds your balance after the check. It is `null` on results inside a batch; read `BatchResult::$creditsRemaining` there instead.

## The `flagged()` helper

`flagged()` returns `true` when the `fraud_score` is at or above a risk threshold (default 75), or when any of a set of fields is `true`. `flagReasons()` lists which of those fired, for example `['fraud_score', 'proxy']`. Both take the same two optional arguments.

| Check | Fields checked by default |
| --- | --- |
| IP address | `proxy`, `vpn`, `tor` |
| Email address | `disposable` |
| Phone number | `VOIP`, `risky` |

Pass your own threshold and fields with the raw API field names (`VOIP`, not `voip`):

```php
$result = $client->phone('+18176791808');

if ($result->flagged(riskThreshold: 85, flagOn: ['VOIP', 'risky', 'recent_abuse'])) {
    $reasons = $result->flagReasons(85, ['VOIP', 'risky', 'recent_abuse']);
    // ...
}
```

The defaults are available as `IpResult::DEFAULT_FLAG_ON`, `EmailResult::DEFAULT_FLAG_ON` and `PhoneResult::DEFAULT_FLAG_ON`.

## Balance and your IP

```php
$credits = $client->balance(); // int, costs no credits
$ip = $client->myIp();         // string, the public IP your server calls from; no API key needed
```

## Errors

Every exception the client throws implements `FraudNova\Exception\FraudNovaException`, so one `catch` handles them all:

```php
use FraudNova\Exception\FraudNovaException;
use FraudNova\Exception\InsufficientCreditsException;

try {
    $result = $client->ip($visitorIp);
} catch (InsufficientCreditsException $e) {
    // buy a credit pack in the FraudNova dashboard
} catch (FraudNovaException $e) {
    // log $e->getMessage() and let the request through, or hold it for review
}
```

| Exception | Status | When |
| --- | --- | --- |
| `AuthenticationException` | 401 | The API key is missing, invalid or revoked. |
| `InsufficientCreditsException` | 402 | Your balance is too low for the check. |
| `AccountSuspendedException` | 403 | The account is suspended. |
| `ValidationException` | 422 | The API rejected the request parameters. |
| `RateLimitException` | 429 | Too many requests, or too many checks in flight at once. `retryAfter()` and `isConcurrencyLimit()` tell you which. |
| `UpstreamException` | 502, 503, 504 | The lookup failed. On a 503 the credit is refunded automatically and `creditRefunded()` returns `true`. |
| `UnexpectedResponseException` | 2xx | The response could not be read as a FraudNova response. |
| `ApiException` | any other error status | Base class of every exception with a status. `statusCode()`, `apiMessage()` and `body()` are on all of them. A 401, 402, 403 or 422 whose body is not a FraudNova JSON response (for example a proxy error page) is a plain `ApiException`. |
| `TransportException` | none | The HTTP client failed, for example a timeout or DNS error. The original exception is `getPrevious()`. |
| `InvalidArgumentException` | none | A value was empty or out of range; no request was sent. |
| `MissingHttpClientException` | none | No PSR-18 client was installed or injected. |

All classes live in the `FraudNova\Exception` namespace.

## Retries

The client does not retry by default. To retry HTTP 429 responses, pass a `RetryPolicy`:

```php
use FraudNova\Client;
use FraudNova\RetryPolicy;

$client = new Client('fn_your_api_key', retry: new RetryPolicy(maxAttempts: 3));
```

`maxAttempts` counts the first attempt, so `3` means up to two retries. Only 429 is retried; every other error is thrown right away. The wait is the `Retry-After` header when the response has one, otherwise a short schedule for the concurrency limit and a longer one for the rate limit. When the attempts run out, the `RateLimitException` is thrown. A `Retry-After` longer than `maxRetryAfter` (60 seconds by default) is not waited for; the `RateLimitException` is thrown right away. Batch requests are safe to retry, because a 429 is returned before any item is checked.

## Bring your own HTTP client

Inject any PSR-18 client with a PSR-17 request factory and stream factory. Timeouts, proxies and TLS settings belong to the HTTP client, so set them there:

```php
use FraudNova\Client;

$http = new \GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 2]);
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$client = new Client('fn_your_api_key', $http, $factory, $factory);
```

Without an injected client, the client uses Guzzle (30 second timeout) or the Symfony HTTP client, whichever is installed.

## Laravel

The service provider and the `FraudNova` facade are registered automatically through package discovery. Add your key to `.env`:

```bash
FRAUDNOVA_API_KEY=fn_your_api_key
```

Then use the facade, or inject `FraudNova\Client`, which is bound as a singleton:

```php
use FraudNova\Laravel\Facades\FraudNova;

$result = FraudNova::ip($request->ip());

if ($result->flagged()) {
    abort(403);
}
```

To change the timeout or turn on retries, publish the config file to `config/fraudnova.php`:

```bash
php artisan vendor:publish --tag=fraudnova-config
```

It reads `FRAUDNOVA_API_KEY`, `FRAUDNOVA_BASE_URL`, `FRAUDNOVA_TIMEOUT` (seconds, default 30) and `FRAUDNOVA_RETRY_MAX_ATTEMPTS` (default 0, no retries; `3` retries a 429 up to twice).

In tests, bind your own client so no real request is sent:

```php
use FraudNova\Client;

$this->app->singleton(Client::class, fn (): Client => new Client('fn_test', $fakeHttpClient, $psr17Factory, $psr17Factory));
```

## Credits and limits

One check costs one credit, whichever type it is. Failed checks are refunded. You get free trial credits when you verify your email address, and you buy more as credit packs, with no subscription. A batch costs one credit per successful item.

The API limits requests per minute for each key and checks in flight for each account. The current limits are in the [API documentation](https://fraudnova.com/docs).

## Resources

* [FraudNova API documentation](https://fraudnova.com/docs)
* [Examples repository](https://github.com/dahleedam/fraudnova-examples)
* [Status](https://fraudnova.com/status)
* [Changelog](https://fraudnova.com/changelog)
* Support: [support@fraudnova.com](mailto:support@fraudnova.com)

## Licence

Released under the MIT license. See [LICENSE](LICENSE).
