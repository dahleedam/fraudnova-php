# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-06

### Added

- `FraudNova\Client` for every API endpoint: IP, email and phone checks, the three batch checks, balance and my IP.
- Typed results (`IpResult`, `EmailResult`, `PhoneResult`, `BatchResult`) with camelCase properties, `toArray()` and the `flagged()` / `flagReasons()` helpers.
- Typed exceptions for every API error status, all implementing `FraudNovaException`.
- Opt-in retry of HTTP 429 responses with `RetryPolicy`, honouring `Retry-After`.
- Automatic PSR-18 client discovery (Guzzle or the Symfony HTTP client), or inject your own client.
- Laravel bridge: auto-discovered service provider, `FraudNova` facade and publishable config.

[Unreleased]: https://github.com/dahleedam/fraudnova-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/dahleedam/fraudnova-php/releases/tag/v1.0.0
