# Changelog

All notable changes to `requestway/laravel-x402` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Because the x402 protocol is young, note that **a new protocol version is a
minor release, not a breaking one**: wire formats live behind versioned codecs,
and support for an older version is kept for reading as long as clients send it.

## [Unreleased]

### Added

- Opt-in `nano:mainnet` support: with `x402.nano.rpc_url` set, Nano payments are verified with one `block_info` call
  against your own node instead of a facilitator; every other network is unchanged. Adds the `nano` network alias,
  per-network `pay_to` and `units_per_usd` on assets that are not USD-pegged, and `RoutingFacilitator`

### Changed

- README documents the Requestway dashboard: what it shows, how to connect it, how reporting behaves and exactly
  which fields are sent

## [0.1.0] - 2026-10-10

Initial release. Implements x402 protocol version 2 and reads version 1.

### Added

- `x402` middleware with fixed prices, named tiers and dynamic pricing
- `#[RequiresPayment]` attribute and a `->paid()` route macro
- Two settlement modes: `before_response` (safe) and `after_response` (fast)
- `enforce`, `observe` and `off` modes, with observe recording demand without charging
- Versioned protocol codecs for v2 (`PAYMENT-REQUIRED`, `PAYMENT-SIGNATURE`, `PAYMENT-RESPONSE`)
  and read support for v1 (`X-PAYMENT`, `X-PAYMENT-RESPONSE`)
- `HttpFacilitator` for the x402 Foundation, Coinbase CDP and self-hosted facilitators,
  with typed exceptions, connection-only retries, static headers and a
  `FacilitatorAuth` contract for facilitators that sign each request
- `FakeFacilitator` plus `X402::fake()` and the `MakesX402Payments` test trait
- Replay protection with atomic cache claims released on failure
- Bypass rules: authenticated users, IP and CIDR allowlists, API keys and closures
- Multiple accepted networks per route, with the specification's canonical asset table
- `x402_payments` persistence with an `X402Payment` model and query scopes
- `PaymentRequired`, `PaymentVerified`, `PaymentSettled`, `PaymentFailed` and `PaymentObserved` events
- `/.well-known/x402` discovery endpoint in the specification's discovery shape
- `x402:install`, `x402:routes`, `x402:test` and `x402:doctor` commands
- Optional batched, queued reporting behind `X402_PLATFORM_KEY`
- Framework-agnostic protocol core under `src/Core`, with no Illuminate dependencies

[Unreleased]: https://github.com/requestway/laravel-x402/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/requestway/laravel-x402/releases/tag/v0.1.0
