# FraudNova PHP SDK — design (2026-11-06)

## Intent

Ship the official PHP client for the FraudNova API as a Composer package, `fraudnova/fraudnova-php`,
so a PHP or Laravel developer can score an IP, email address or phone number in five lines, without
writing cURL code or re-implementing the error envelope. The package is also a marketing asset: a
Packagist listing (and the automatic Packalyst mirror for Laravel packages) is a cheap, high-authority
backlink, and the customer base (affiliate/CPA marketers) leans on PHP and Laravel.

Sibling projects to stay consistent with: the n8n community node (`n8n-nodes-fraudnova`) and the public
examples repository (`dahleedam/fraudnova-examples`, `examples/php/*.php`).

## Goals

- `composer require fraudnova/fraudnova-php` then `(new FraudNova\Client('fn_…'))->ip('8.8.8.8')->fraudScore`.
- Every public endpoint wrapped: `GET /check`, `/check/email`, `/check/phone`, the three batch
  endpoints, `GET /balance`, public `GET /my-ip`.
- Typed result objects with a `flagged()` helper that mirrors the n8n node's `flagged` / `flag_reasons`.
- Every API error status mapped to a typed exception; opt-in retry on 429.
- Works with any PSR-18 HTTP client; Guzzle discovered automatically when installed.
- A thin optional Laravel bridge (service provider, facade, publishable config) in the same package.
- Tests with a mocked PSR-18 client (no network), PHPStan level max, Pint, CI on PHP 8.2–8.5.
- Repo ready for the owner to push to `github.com/dahleedam/fraudnova-php` and register on Packagist.

## Non-goals (v1)

- No webhook signature verification helper (the examples repo already has one; may come in 1.1).
- No automatic chunking of batches above 100 items (see "Batch" below).
- No async/concurrent requests, no caching, no logging.
- No `FraudNova::fake()` test helper for Laravel (users bind their own `Client` with a mocked PSR-18 client).
- No retries on anything but 429.

## Anti-goals

- The package, its README, tests, this document and commit messages must never name the upstream
  scoring provider (the owner's standing rule; the two forbidden strings are the provider's full name
  and its four-letter abbreviation) and must never describe keys, credentials or quota internals. A
  test greps the repository for the forbidden strings, assembling them from fragments so the test file
  itself stays clean. `docs/superpowers/` IS committed (the owner's convention in the main app), so the
  spec and plan are written to pass the same guard.
- The API key must never appear in an exception message, log line or `__toString()`.
- No heavy runtime dependencies: runtime requires are the PSR interfaces only.

## Constraints

- PHP ≥ 8.2. CI matrix 8.2, 8.3, 8.4, 8.5 (the local toolchain is 8.5; 8.2 is also installed via Herd).
- Runtime dependencies: `psr/http-client ^1.0`, `psr/http-factory ^1.0`, `psr/http-message ^1.1 || ^2.0`,
  `ext-json`. Nothing else. `guzzlehttp/guzzle` is a `suggest` and a dev dependency.
- Laravel bridge supports `illuminate/support` / `illuminate/contracts` `^11 || ^12 || ^13`
  (dev dependency only; the provider is only loaded when Laravel's package discovery finds it).
- Licence MIT, copyright "FraudNova", same text as the n8n node.
- Commits end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. The owner pushes.

## The API being wrapped (source of truth: `app/Support/OpenApiDocument.php` in the main app)

Base URL `https://fraudnova.com/api/v1`. Auth header `Authorization: Bearer fn_…`. All responses JSON.

| Call | Request | Success body |
| --- | --- | --- |
| `GET /check` | query `ip` (required), `strictness` 0–3 (default 2), `mobile` bool, `user_agent` ≤512, `user_language` ≤32 | `{success:true, data:{…IP fields}, credits_remaining}` |
| `GET /check/email` | query `email` | `{success:true, data:{…email fields}, credits_remaining}` |
| `GET /check/phone` | query `phone`, optional `country` (ISO-3166 alpha-2) | `{success:true, data:{…phone fields}, credits_remaining}` |
| `POST /check/batch` | JSON `{ips:[…≤100], strictness?, mobile?, user_agent?, user_language?}` | `{success:true, results:[{ip, success, data|null, error|null}], credits_remaining}` |
| `POST /check/email/batch` | JSON `{emails:[…≤100]}` | `{success:true, results:[{email, success, data, error}], credits_remaining}` |
| `POST /check/phone/batch` | JSON `{phones:[…≤100], country?}` (one hint for the whole batch) | `{success:true, results:[{phone, success, data, error}], credits_remaining}` |
| `GET /balance` | – (free) | `{success:true, credits_remaining}` |
| `GET /my-ip` | – (free, no auth header needed) | `{success:true, ip}` |

Per-item batch `error` codes: `invalid_ip` / `invalid_email` / `invalid_phone`, `insufficient_credits`,
`upstream_failed`. A batch is always HTTP 200 when the envelope is valid; 402 only if the balance was
zero at the start; 422 on a bad envelope.

Error envelope `{success:false, message}` with:

| Status | Meaning | Message text today |
| --- | --- | --- |
| 401 | missing/invalid/revoked token | "API token missing." / "Invalid or revoked API token." |
| 402 | insufficient credits | "Insufficient credits." |
| 403 | account suspended | "Account suspended." |
| 422 | validation | first validation message |
| 429 | per-key rate limit (600/min) | "Rate limit exceeded. Try again shortly." |
| 429 | per-account concurrency cap (default 10 in flight) | "Too many concurrent requests for this account. Please retry." |
| 503 | upstream lookup failed; the credit was refunded automatically | "Upstream lookup failed." (message may vary) |

Verified while reading the main app: neither 429 carries a `Retry-After` header today (the rate-limit
renderer builds a fresh JSON response without the exception's headers; the concurrency 429 never had one).
The SDK honours `Retry-After` when present and falls back to a schedule otherwise.

Cloudflare fronts the API, so a non-JSON HTML body with a 5xx status (502/520/etc.) is possible during an
outage. The main app now answers 503 for upstream failures precisely because Cloudflare masks 502 bodies.

Result fields (from the OpenAPI schemas):

- IP `CheckData`: `fraud_score` int; bools `proxy`, `vpn`, `tor`, `active_vpn`, `active_tor`, `recent_abuse`,
  `bot_status`; nullable strings `country_code`, `region`, `city`, `ISP`, `organization`; nullable int `ASN`;
  nullable strings `operating_system`, `browser`, `device_brand`, `device_model` (populated only with `user_agent`).
- Email `EmailCheckData`: bools `valid`, `disposable`, `catch_all`, `generic`, `common`, `dns_valid`, `honeypot`,
  `frequent_complainer`, `suspect`, `recent_abuse`, `leaked`, `risky_tld`, `spf_record`, `dmarc_record`;
  ints `fraud_score`, `smtp_score`, `overall_score`; strings `deliverability`, `spam_trap_score`, `result_type`,
  `sanitized_email`; nullable strings `first_seen`, `domain_age`.
- Phone `PhoneCheckData`: bools `valid`, `recent_abuse`, `VOIP`, `prepaid`, `risky`, `active`, `do_not_call`,
  `leaked`, `spammer`; int `fraud_score`, `dialing_code`; strings `carrier`, `line_type`, `country`, `region`,
  `city`, `timezone`, `zip_code`, `active_status`, `formatted`, `local_format`.

Any field may be absent from a given response; the SDK must treat every field as optional when parsing
and must keep unknown fields (forward compatibility) reachable through `toArray()`.

**Lenient typing (my call):** the OpenAPI document is hand-authored, so a field's wire type may disagree
with it. `fromArray()` never throws on a mistyped field: a typed property gets the value only when it is
the expected scalar type (ints also accepted from numeric strings), otherwise `null`; the raw value stays
in `toArray()`. Known case: email `first_seen` and `domain_age` are documented as strings but most likely
arrive as objects `{human, timestamp, iso}` from the upstream. `EmailResult::$firstSeen` / `$domainAge`
are `?string`: a string is used as-is; an object yields `iso` for `first_seen` and `human` for
`domain_age`. (Follow-up for the main app, not this package: correct the OpenAPI schema once a real
response confirms the shape.)

## Package shape

```
fraudnova-php/
  composer.json            fraudnova/fraudnova-php, type library, PSR-4 "FraudNova\\" => src/
  src/
    Client.php             the one entry point
    ClientBuilder? (no — named constructor args are enough, see below)
    IpOptions.php          readonly: strictness, mobile, userAgent, userLanguage
    RetryPolicy.php        readonly: opt-in 429 retry settings
    Http/
      HttpClientDiscovery.php   finds a PSR-18 client + PSR-17 factories when none are injected
      Transport.php             builds requests, sends, decodes JSON, maps errors, applies RetryPolicy
    Result/
      IpResult.php, EmailResult.php, PhoneResult.php   typed read-only results + flagged()/flagReasons()
      BatchResult.php, BatchItem.php                   batch envelope + per-item outcome
      Balance? (no — balance() returns int)
    Exception/
      FraudNovaException.php          interface every package exception implements
      ApiException.php                base for HTTP error envelopes: status, apiMessage, body
      AuthenticationException.php     401
      InsufficientCreditsException.php 402
      AccountSuspendedException.php   403
      ValidationException.php         422
      RateLimitException.php          429 (+ retryAfter(), isConcurrencyLimit())
      UpstreamException.php           503 (and 502/504): "the credit was refunded"
      TransportException.php          wraps PSR-18 ClientExceptionInterface (network)
      UnexpectedResponseException.php non-JSON / malformed success body
      MissingHttpClientException.php  no PSR-18 client found and none injected
      InvalidArgumentException.php    client-side input errors (empty subject, batch > 100, strictness)
    Laravel/
      FraudNovaServiceProvider.php
      Facades/FraudNova.php
  config/fraudnova.php     published Laravel config
  tests/                   PHPUnit 11+, namespace FraudNova\Tests
  phpunit.xml.dist, phpstan.neon.dist, pint.json
  .github/workflows/ci.yml (+ a small release.yml that creates a GitHub Release on a v* tag)
  README.md, CHANGELOG.md, LICENSE, .gitignore, .gitattributes (export-ignore tests/docs/.github)
  docs/superpowers/specs|plans/…
```

## Public API

```php
use FraudNova\Client;
use FraudNova\IpOptions;
use FraudNova\RetryPolicy;

$client = new Client('fn_your_key');                       // Guzzle discovered automatically
$client = new Client(
    apiKey: 'fn_your_key',
    httpClient: $psr18,                                     // any PSR-18 client
    requestFactory: $psr17, streamFactory: $psr17,          // PSR-17 (optional when discoverable)
    baseUrl: 'https://fraudnova.com/api/v1',                // default; trailing slash tolerated
    retry: new RetryPolicy(maxAttempts: 3),                 // opt-in 429 retry; default null = off
);

$ip    = $client->ip('8.8.8.8');                            // IpResult
$ip    = $client->ip('8.8.8.8', new IpOptions(strictness: 3, mobile: true, userAgent: $ua, userLanguage: 'en-US'));
$email = $client->email('user@example.com');                // EmailResult
$phone = $client->phone('18176791808', country: 'US');      // PhoneResult

$batch = $client->batchIp(['8.8.8.8', '1.1.1.1'], new IpOptions(strictness: 2));  // BatchResult<IpResult>
$batch = $client->batchEmail([...]);                        // BatchResult<EmailResult>
$batch = $client->batchPhone([...], country: 'US');         // BatchResult<PhoneResult>

$credits = $client->balance();                              // int
$myIp    = $client->myIp();                                 // string
```

Behaviour:

- Subjects are trimmed; an empty subject throws `InvalidArgumentException` before any request.
- `IpOptions` validates `strictness` 0–3 in its constructor; only non-null options are sent.
- `country` is trimmed and upper-cased, nothing more (the API validates it).
- Batch methods send exactly one HTTP request. An empty list or more than `Client::BATCH_MAX` (100)
  items throws `InvalidArgumentException`; the README shows `array_chunk($list, Client::BATCH_MAX)`.
  (My call: one method call = one request keeps credit accounting and 402 semantics transparent; the
  n8n node chunks because its users cannot write a loop.)
- `myIp()` sends no Authorization header. Every other call sends it.
- Headers on every request: `Accept: application/json`, `User-Agent: fraudnova-php/<version> php/<PHP_VERSION>`,
  and `Content-Type: application/json` on POST.
- `Client::VERSION` is a string constant, `1.0.0` for the first release.

### Results

`IpResult`, `EmailResult`, `PhoneResult` are `final readonly` classes built from the `data` array via a
static `fromArray(array $data, ?int $creditsRemaining)`. Each exposes:

- camelCase public properties for every documented field (`fraudScore`, `proxy`, `activeVpn`, `isp`,
  `asn`, `countryCode`, …; email `sanitizedEmail`, phone `voip`, `lineType`, …), all nullable except
  `fraudScore`, which defaults to 0 when missing (and `fromArray` must not throw on a missing field).
- `public readonly ?int $creditsRemaining` (null on batch items, because the batch envelope carries one
  number for the whole request; set on single checks).
- `toArray(): array` — the raw `data` array exactly as the API returned it (unknown fields included).
- `flagReasons(int $riskThreshold = 75, ?array $flagOn = null): list<string>` and
  `flagged(int $riskThreshold = 75, ?array $flagOn = null): bool` — identical semantics to the n8n node:
  `fraud_score >= threshold` adds `'fraud_score'`; each name in `$flagOn` whose raw value is `true` adds
  that name; `flagged` is `reasons !== []`. Defaults when `$flagOn` is null: IP `['proxy','vpn','tor']`,
  email `['disposable']`, phone `['VOIP','risky']` (the raw API field names, so `'VOIP'` not `'voip'`).

`BatchResult` (`final readonly`): `items: list<BatchItem>`, `creditsRemaining: int`, and helpers
`successful(): list<BatchItem>`, `failed(): list<BatchItem>`, `hasFailures(): bool`, `count()`
(implements `Countable` and `IteratorAggregate<BatchItem>`). `BatchItem`: `subject: string`, `success: bool`,
`result: IpResult|EmailResult|PhoneResult|null`, `error: ?string` (the raw code), plus
`isInvalid()`, `isInsufficientCredits()`, `isUpstreamFailed()`. Items stay in input order as returned.

### Exceptions

All implement `FraudNova\Exception\FraudNovaException` (an interface extending `Throwable`) so
`catch (FraudNovaException $e)` catches everything the package throws.

`ApiException` (extends `RuntimeException`) carries `statusCode(): int`, `apiMessage(): string` (the
envelope's `message`, or the raw body trimmed to 200 chars when there was no JSON message), `body(): array`
(decoded JSON or `[]`). Its `getMessage()` is `"FraudNova API error (HTTP 429): Rate limit exceeded…"`.
Subclasses by status: 401 `AuthenticationException`, 402 `InsufficientCreditsException`,
403 `AccountSuspendedException`, 422 `ValidationException`, 429 `RateLimitException`,
502/503/504 `UpstreamException` (message appends "The credit for this check was refunded." only for a 503
that carried the API's JSON envelope — a Cloudflare HTML 503 is just "temporarily unavailable", as are
502/504). Any other non-2xx → plain `ApiException`.
`RateLimitException::retryAfter(): ?int` (seconds, parsed from `Retry-After` as delta-seconds or
HTTP-date; null when absent) and `isConcurrencyLimit(): bool` (true when the message contains
"Too many concurrent requests").

A 2xx with a body that is not a JSON object, or a JSON object with `success !== true`, throws
`UnexpectedResponseException` (also an `ApiException` subclass, so it carries the status).
PSR-18 `ClientExceptionInterface` is wrapped in `TransportException` (previous = original).

### Retry (opt-in)

`RetryPolicy` (`final readonly`): `maxAttempts` (total attempts incl. the first, default 3),
`concurrencyDelays` seconds `[1, 2, 4]`, `rateLimitDelays` seconds `[5, 15, 40]`, `honourRetryAfter`
(default true), and `sleeper: ?Closure(float $seconds): void` (default `usleep`; tests inject a recorder).
Only HTTP 429 is retried. Delay = `Retry-After` when present and honoured, otherwise the schedule entry
for the attempt number (concurrency vs rate-limit chosen by `isConcurrencyLimit()`); when the schedule
runs out or `maxAttempts` is reached the `RateLimitException` propagates. Batch POSTs are safe to retry
on 429 because a 429 is returned before any item is processed. `balance()`/`myIp()` use the same policy.

### Discovery

`HttpClientDiscovery::client()` / `::requestFactory()` / `::streamFactory()` pick, in order:
1. Guzzle: `GuzzleHttp\Client` (constructed with `timeout` 30 and `connect_timeout` 10) and
   `GuzzleHttp\Psr7\HttpFactory`;
2. Symfony: `Symfony\Component\HttpClient\Psr18Client` (it is also every PSR-17 factory);
3. otherwise throw `MissingHttpClientException` whose message says to run
   `composer require guzzlehttp/guzzle` or inject a PSR-18 client.
(Both real branches are dev-installed and tested through an injected `class_exists` closure; a
`php-http/discovery` branch was dropped as untestable without yet another dev dependency.)
Discovery runs lazily on first request, not in the constructor, and only for the pieces not injected
(injecting a client but no factories is allowed as long as factories are discoverable).
(My call: a 40-line built-in lookup instead of requiring `php-http/discovery`, whose Composer plugin
prompts on install and adds a dependency for the common Guzzle case.)

### Laravel bridge

- `FraudNova\Laravel\FraudNovaServiceProvider`: `register()` merges `config/fraudnova.php` under the
  `fraudnova` key and binds `FraudNova\Client` as a singleton built from `config('fraudnova.api_key')`,
  `base_url`, `timeout` (applied by constructing the discovered Guzzle client with that timeout) and
  `retry_max_attempts` (0 or null = no retry policy). `boot()` publishes the config with tag `fraudnova-config`.
  Throws a clear `InvalidArgumentException` on first resolve when `api_key` is empty.
- Facade `FraudNova\Laravel\Facades\FraudNova` with `@method` docblocks for every `Client` method.
- `composer.json` `extra.laravel.providers` / `aliases` for package discovery.
- `config/fraudnova.php` keys: `api_key` = `env('FRAUDNOVA_API_KEY')`, `base_url` =
  `env('FRAUDNOVA_BASE_URL', 'https://fraudnova.com/api/v1')`, `timeout` = `env('FRAUDNOVA_TIMEOUT', 30)`,
  `retry_max_attempts` = `env('FRAUDNOVA_RETRY_MAX_ATTEMPTS', 0)`.
- Tested with `orchestra/testbench` (one test class: provider registers, facade resolves, config env
  values reach the client, empty key fails clearly). Note for the owner: the FraudNova application
  itself also owns a `config/fraudnova.php`; the bridge is not meant to be installed there.

## Quality bar

- PHPUnit (11 or 12, whichever `composer require --dev` resolves on PHP 8.2), tests in `tests/`,
  a `tests/Support/FakeHttpClient` PSR-18 double that queues PSR-7 responses and records sent requests
  (`nyholm/psr7` as the dev PSR-7/PSR-17 implementation). No test performs network I/O.
- Coverage expectations: request building per endpoint (method, path, query encoding, JSON body,
  headers, no auth on `/my-ip`), result parsing incl. missing fields, every status → exception class,
  `Retry-After` parsing, retry schedule + sleeper calls + give-up, batch parsing incl. all error codes
  and unknown codes, flagged defaults per type and custom thresholds, discovery order and the
  missing-client error, Laravel provider via testbench, and the leak-guard test
  (`tests/LeakGuardTest.php`: recursively greps every file in the repo except `vendor/`, `.git/` and
  `*.lock` files (base64 blobs false-positive on the four-letter string) for the two forbidden strings,
  case-insensitive, assembled from fragments inside the test, and fails naming the file).
- PHPStan level `max` on `src/` and `tests/`, 0 errors; `orchestra/testbench ^9 || ^10 || ^11` as a dev
  dependency gives the Laravel types and the bridge test (Laravel 13 / Testbench 11 need PHP 8.3, so the
  8.2 job resolves Testbench 9 or 10 — the bridge must work on Laravel 11, 12 and 13).
- `composer.lock` is not committed (library convention; CI resolves fresh).
- Pint with the default preset, `vendor/bin/pint --test` clean.
- `composer validate --strict` passes.
- CI (`.github/workflows/ci.yml`): matrix PHP 8.2/8.3/8.4/8.5 × prefer-stable, plus one 8.2 × prefer-lowest
  job; steps `composer validate --strict`, install, `pint --test`, `phpstan`, `phpunit`. Concurrency
  group cancels superseded runs (same as the n8n repo).
- Composer scripts: `test`, `lint` (pint --test), `analyse` (phpstan), `check` (all three).

## README outline

Title + one-paragraph pitch (same wording family as the n8n node README), badges (CI, Packagist version,
PHP version, licence), Requirements, Installation (`composer require fraudnova/fraudnova-php`; "needs any
PSR-18 client; Guzzle is picked up automatically: `composer require guzzlehttp/guzzle`"), Quickstart
(5 lines), Checks (ip/email/phone with options), Batch checks (one request, ≤100, `array_chunk`,
per-item errors), Results & `flagged()`, Balance & my IP, Errors (exception table), Retries, Using your
own HTTP client, Laravel (provider discovery, `FRAUDNOVA_API_KEY`, facade usage, publish config, swapping
the client in tests), Credits & limits (1 credit per check, failed checks refunded, 600/min, 10 in
flight — stated as "see the docs for current limits"), Links (docs, examples repo, status, changelog,
support@fraudnova.com), Licence. Nothing in it names the upstream provider or key internals.

## Release & hand-over (not code)

- Single `main` branch, conventional-style commits, `CHANGELOG.md` in Keep-a-Changelog form with a
  `1.0.0` section dated on release day.
- The owner: `git remote add origin git@github.com:dahleedam/fraudnova-php.git && git push -u origin main`,
  then `git tag v1.0.0 && git push origin v1.0.0`, submit the GitHub URL at packagist.org/packages/submit,
  enable the Packagist GitHub hook (auto-update), confirm Packalyst picks it up, and add a `/docs`
  "PHP SDK" install line plus a changelog entry in the main app (separate session).

## Left to the builder

- Exact dev-dependency versions (resolve with Composer on PHP 8.2).
- Internal helper names, test file layout, docblock wording.
- Whether `Transport` is one class or two, as long as `Client` stays thin and testable.
- The GitHub Release workflow is optional polish; skip it if it costs more than 20 lines.
