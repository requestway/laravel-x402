# Laravel x402

[![Tests](https://github.com/requestway/laravel-x402/actions/workflows/tests.yml/badge.svg)](https://github.com/requestway/laravel-x402/actions/workflows/tests.yml)
[![Static analysis](https://github.com/requestway/laravel-x402/actions/workflows/phpstan.yml/badge.svg)](https://github.com/requestway/laravel-x402/actions/workflows/phpstan.yml)
[![Latest version](https://img.shields.io/packagist/v/requestway/laravel-x402.svg)](https://packagist.org/packages/requestway/laravel-x402)
[![License](https://img.shields.io/packagist/l/requestway/laravel-x402.svg)](LICENSE)

**Charge AI agents for your Laravel API in one line.** Add `->middleware('x402:0.01')` to a route and it starts returning
HTTP 402 with machine-readable payment requirements. Any [x402](https://github.com/x402-foundation/x402) client — an agent
using `@x402/fetch`, an MCP tool, a Python script — reads the 402, signs a stablecoin payment, and retries. A facilitator
verifies the signature and settles on chain. USDC lands in your wallet, your controller runs, and the client gets its
response with a transaction hash attached. No accounts, no API keys, no invoices, no card forms, and no human in the loop.

```php
Route::get('/market-data', MarketDataController::class)->middleware('x402:0.01');
```

That is the whole integration. The package implements [x402 protocol v2](docs/SPEC_NOTES.md) and is the reference
implementation for PHP.

To see what agents pay you, connect the free [Requestway dashboard](#requestway-dashboard) with one environment
variable: earnings per route, failed payments by reason, and demand on routes you only observe.

---

## Contents

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [60-second quickstart](#60-second-quickstart)
- [Pricing](#pricing)
- [Declaring paid routes](#declaring-paid-routes)
- [Settlement modes](#settlement-modes)
- [Bypass rules](#bypass-rules)
- [Observe mode](#observe-mode)
- [Facilitators](#facilitators)
- [Testing](#testing)
- [Events](#events)
- [Payment records](#payment-records)
- [Discovery](#discovery)
- [Artisan commands](#artisan-commands)
- [Security](#security)
- [Testnet to mainnet checklist](#testnet-to-mainnet-checklist)
- [Configuration reference](#configuration-reference)
- [Requestway dashboard](#requestway-dashboard)
- [FAQ](#faq)

---

## How it works

```
  AI agent                        Your Laravel app                   Facilitator        Chain
     │                                   │                                │               │
     │  GET /market-data                 │                                │               │
     ├──────────────────────────────────►│                                │               │
     │                                   │                                │               │
     │  402 Payment Required             │                                │               │
     │  PAYMENT-REQUIRED: base64 JSON    │                                │               │
     │  { amount, asset, payTo,          │                                │               │
     │    network, resource, expiry }    │                                │               │
     │◄──────────────────────────────────┤                                │               │
     │                                   │                                │               │
  ┌──┴────────────────────────┐          │                                │               │
  │ sign an EIP-3009 transfer │          │                                │               │
  │ authorization             │          │                                │               │
  └──┬────────────────────────┘          │                                │               │
     │  GET /market-data                 │                                │               │
     │  PAYMENT-SIGNATURE: base64 JSON   │                                │               │
     ├──────────────────────────────────►│                                │               │
     │                              ┌────┴─────────────────────┐          │               │
     │                              │ validate locally:        │          │               │
     │                              │ amount, asset, payTo,    │          │               │
     │                              │ network, resource,       │          │               │
     │                              │ expiry, replay claim     │          │               │
     │                              └────┬─────────────────────┘          │               │
     │                                   │  POST /verify                  │               │
     │                                   ├───────────────────────────────►│               │
     │                                   │◄───────────────────────────────┤               │
     │                              ┌────┴─────────────────────┐          │               │
     │                              │ run your controller      │          │               │
     │                              └────┬─────────────────────┘          │               │
     │                                   │  POST /settle                  │               │
     │                                   ├───────────────────────────────►│               │
     │                                   │                                │  USDC to your │
     │                                   │                                ├──────────────►│
     │                                   │◄───────────────────────────────┤     wallet    │
     │  200 OK                           │                                │               │
     │  PAYMENT-RESPONSE: base64 JSON    │                                │               │
     │  { success, transaction, payer }  │                                │               │
     │◄──────────────────────────────────┤                                │               │
     │                                   │                                │               │
```

Funds move directly from the payer's wallet to yours. **This package never holds, routes or custodies money** — it only
checks signatures and asks a facilitator to broadcast the transfer the payer already signed.

## Requirements

- PHP 8.2+
- Laravel 12, 13 or newer
- A wallet address to receive payments (any EVM address — you do not need a private key on the server)
- A facilitator URL (free for testnets, no account needed)

No blockchain node, no web3 extension, no `ext-bcmath`, no queue worker required.

## 60-second quickstart

```bash
composer require requestway/laravel-x402
php artisan x402:install   # publishes config, asks for your wallet, network and facilitator
php artisan migrate        # optional: records every payment
```

Protect a route:

```php
use Illuminate\Support\Facades\Route;

Route::get('/market-data', fn () => ['btc' => 67_000])->middleware('x402:0.01');
```

Check the whole exchange without a wallet, a chain, or a single real cent:

```bash
php artisan x402:test /market-data
```

```
 GET /market-data
 Facilitator ................................ fake (nothing is charged)

 1. Unpaid request ........................................ HTTP 402
 PAYMENT-REQUIRED ... {"x402Version":2,"error":"Payment of $0.01 is required…

 Resource .......................... http://localhost/market-data
 Network ................................................ Base Sepolia
 Amount ................................ 10000 atomic units ($0.01)
 Pay to .................... 0x209693Bc6afc0C5328bA36FaF03C514EF312287C

 2. Paid request .......................................... HTTP 200
 PAYMENT-RESPONSE ... {"success":true,"transaction":"0x0000…0001"}

 Settled ....................................................... yes
 The full exchange works.
```

Then verify your real configuration:

```bash
php artisan x402:doctor
```

Defaults are deliberately safe: **Base Sepolia testnet**, so an unconfigured install cannot move real money. See the
[testnet to mainnet checklist](#testnet-to-mainnet-checklist) when you are ready to charge.

## Pricing

Prices are written in USD and converted to the asset's atomic units with string arithmetic — never floats. USDC has six
decimals, so `0.01` becomes `10000`. Anything finer than one atomic unit rounds **up**, so a route can never be
underpriced by rounding.

**Fixed price:**

```php
Route::get('/report', ReportController::class)->middleware('x402:0.25');
```

**Named tiers** from `config/x402.php`:

```php
'tiers' => [
    'micro' => '0.001',
    'standard' => '0.01',
    'premium' => '0.10',
],
```

```php
Route::get('/report', ReportController::class)->middleware('x402:premium');
```

**Dynamic pricing** with a closure — return `null` to fall back to the route's own price:

```php
use Requestway\X402\Facades\X402;

X402::priceUsing(function (Request $request, RoutePayment $route): ?string {
    return match (true) {
        $request->boolean('full_history') => '1.00',
        $request->user()?->onPlan('pro') === true => '0.001',
        default => null,
    };
});
```

**Dynamic pricing** with a resolver class, for anything worth testing on its own:

```php
final class UsageBasedPrice implements Requestway\X402\Pricing\PriceResolver
{
    public function resolve(Request $request, RoutePayment $route): string|int|float|null
    {
        return bcmul('0.0001', (string) $request->integer('rows', 1), 6);
    }
}

X402::priceUsing(UsageBasedPrice::class);
```

**Multiple payment options.** Every accepted network is advertised in `accepts`, and the client pays on whichever chain
it holds funds on:

```env
X402_NETWORK=eip155:8453
X402_ADDITIONAL_NETWORKS=eip155:84532,eip155:42161
```

```php
Route::get('/report', ReportController::class)
    ->middleware('x402:0.25,networks=eip155:8453|eip155:42161');
```

## Declaring paid routes

Four ways, all equivalent. Pick whichever suits the codebase.

**Middleware:**

```php
Route::get('/report', ReportController::class)->middleware('x402:0.01');
Route::get('/report', ReportController::class)->middleware('x402:0.25,free-for-auth');
Route::get('/report', ReportController::class)->middleware('x402:premium');
Route::get('/report', ReportController::class)
    ->middleware('x402:0.01,description=Daily report,mime=text/csv,timeout=30');
```

**Fluent macro:**

```php
Route::get('/report', ReportController::class)->paid('0.01');

Route::get('/report', ReportController::class)->paid('0.01', [
    'description' => 'Daily report',
    'mime_type' => 'text/csv',
    'free_for_auth' => true,
]);
```

**Attribute** on a controller or action:

```php
use Requestway\X402\Attributes\RequiresPayment;

class ReportController
{
    #[RequiresPayment(price: '0.01', description: 'Daily report', mimeType: 'text/csv')]
    public function show(): JsonResponse
    {
        // ...
    }
}
```

```php
Route::get('/report', [ReportController::class, 'show'])->middleware('x402');
```

To honour attributes app-wide without touching each route, push the attribute middleware into a group — routes with no
attribute pass straight through:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', Requestway\X402\Http\Middleware\HonorPaymentAttributes::class);
})
```

**Every middleware option**

| Option | Example | Meaning |
| --- | --- | --- |
| price (first parameter) | `x402:0.01`, `x402:premium` | USD price or tier name |
| `free-for-auth` | `x402:0.25,free-for-auth` | signed-in users are not charged |
| `paid-for-auth` | `x402:0.01,paid-for-auth` | charge even when `bypass.auth.always` is on |
| `description=` | `description=Daily report` | shown to agents and directories |
| `mime=` | `mime=text/csv` | what the client is buying |
| `network=` | `network=base` | charge on one specific network |
| `networks=` | `networks=base\|base-sepolia` | accept several |
| `pay_to=` | `pay_to=0x…` | a different wallet for this route |
| `settle=` | `settle=after_response` | per-route settlement mode |
| `timeout=` | `timeout=30` | advertised `maxTimeoutSeconds` |
| `tags=` | `tags=finance\|reports` | discovery tags |

## Settlement modes

```php
'settlement' => ['mode' => env('X402_SETTLEMENT_MODE', 'before_response')],
```

**`before_response` (default, safe).** Verify → run your controller → settle → respond. A settlement failure means the
response body is thrown away and the client gets a 402. A client is never served a paid resource without a committed
payment, and a controller that throws costs the client nothing.

**`after_response` (fast).** Verify → run your controller → respond → settle in `terminate()`. The client gets its
response sooner; the cost is a window where a served response can later fail to settle. Those failures fire
`PaymentFailed`, are logged at error level, and are recorded with `status = failed` so you can reconcile them.

Both modes verify before your controller runs, which satisfies the protocol's requirement that at least one check happens
before the resource executes.

## Bypass rules

Requests matching any enabled rule are served for free, with an `X-X402-Bypass` header naming the rule that matched — so
"why wasn't this charged?" always has an answer.

```php
'bypass' => [
    'auth' => ['always' => false, 'guards' => []],
    'ips' => ['203.0.113.4', '10.0.0.0/8'],
    'api_keys' => ['header' => 'X-API-Key', 'keys' => ['...']],
],
```

- **Authenticated users** — per route with `free-for-auth`, or everywhere with `X402_FREE_FOR_AUTH=true`.
- **Allowlisted IPs and CIDR ranges** — for your own monitoring and internal callers.
- **API keys** — a shared secret in a header, compared with `hash_equals`, for customers who already pay you another way.
- **A closure** — anything else:

```php
X402::bypassUsing(fn (Request $request, RoutePayment $route): bool
    => $request->user()?->hasCredits() === true);
```

## Observe mode

```env
X402_MODE=observe
```

Nothing is ever charged. Every request that *would* have been charged is logged, recorded with `status = observed`,
fires a `PaymentObserved` event, and comes back with an `X-X402-Would-Charge: $0.25` header.

This is how you find out whether agents actually want your endpoint before you put a price on it:

```php
X402Payment::query()
    ->observed()
    ->between(now()->subWeek(), now())
    ->get()
    ->groupBy('route')
    ->map(fn ($rows) => $rows->count().' requests, $'.$rows->sum('amount_usd').' foregone');
```

On a high-traffic endpoint, one row per request adds up: set `X402_RECORD_OBSERVED=false` to keep the events and the
logs without the table, or sample in a `PaymentObserved` listener.

`X402_MODE=off` disables the package entirely; protected routes behave as if they were never protected, and the
discovery endpoint advertises nothing — there is no point telling an agent a price you are not charging.

## Facilitators

A facilitator verifies signatures and broadcasts settlement transactions. It never holds your funds — it submits a
transfer the payer already signed, to the address you configured.

```env
X402_FACILITATOR_URL=https://x402.org/facilitator
X402_FACILITATOR_TOKEN=            # sent as a bearer token when set
X402_FACILITATOR_TIMEOUT=30
```

| Facilitator | Use |
| --- | --- |
| `https://x402.org/facilitator` | free, no account, **testnets only** |
| Coinbase CDP | managed mainnet with KYT/OFAC screening |
| Self-hosted | full control; any URL exposing `/verify`, `/settle` and `/supported` |

Some facilitators sign each request rather than taking a long-lived token — the Coinbase CDP facilitator is one. Point
`x402.facilitator.auth` at a class that builds the headers per call:

```php
use Requestway\X402\Core\Facilitator\FacilitatorAuth;

final class CdpAuth implements FacilitatorAuth
{
    public function headers(string $operation): array
    {
        // $operation is 'verify', 'settle' or 'supported'.
        return ['Authorization' => 'Bearer '.$this->jwtFor('/platform/v2/x402/'.$operation)];
    }
}
```

```env
X402_FACILITATOR_URL=https://api.cdp.coinbase.com/platform/v2/x402
X402_FACILITATOR_AUTH=App\Services\CdpAuth
```

Anything implementing the `Facilitator` contract works, including a local one:

```php
X402::useFacilitator(new MyOwnFacilitator);
```

**Failure handling.** Retries cover connection failures only — an HTTP error means the facilitator answered, so repeating
the call would be wrong. A timed-out `/settle` is an indeterminate outcome (the payment may have gone through), so it is
never retried unless you explicitly opt in with `X402_FACILITATOR_SETTLE_RETRIES`. When the facilitator cannot be reached
at all, the request gets a `502` and your resource is not served; set `X402_ON_FACILITATOR_ERROR=fail_open` if you would
rather give the resource away than turn an outage into downtime.

A settlement that comes back with the protocol's non-terminal `settlement_pending` reason is retried exactly once and
then recorded as `pending` rather than `failed`, because the transaction may still confirm on chain.

**Nano without a facilitator.** `nano:mainnet` payments can be checked directly against a Nano node you run or trust.
The client publishes a send block to your Nano address and presents its hash; the package reads that block with one
`block_info` call and serves the route only if it is a confirmed send of at least the price to you. Nothing is signed or
broadcast server side, and every other network still goes to your facilitator.

```env
X402_NANO_RPC_URL=http://127.0.0.1:7076
```

```php
// config/x402.php
'assets' => [
    'nano:mainnet' => [
        'address' => 'XNO', 'decimals' => 30, 'symbol' => 'XNO', 'name' => 'Nano',
        'pay_to' => 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3',
        'units_per_usd' => '1.25', // XNO charged per US dollar of route price; you keep it current
    ],
],
```

```php
Route::get('/report', ReportController::class)->middleware('x402:0.01,networks=base|nano');
```

XNO is not USD-pegged, so it is never a default: prices stay in USD and `units_per_usd` is the rate you choose. Each
block can buy one response, and blocks older than `X402_REPLAY_TTL` are refused. See
[docs/SPEC_NOTES.md](docs/SPEC_NOTES.md) §14.

## Testing

`X402::fake()` swaps in an in-memory facilitator that accepts payments and records them, in the style of `Http::fake()`.
The `MakesX402Payments` trait builds payloads for your own paid routes, so a test can pay without a wallet or a chain.

```php
use Requestway\X402\Facades\X402;
use Requestway\X402\Testing\MakesX402Payments;

uses(MakesX402Payments::class);

it('charges for the report', function () {
    X402::fake();

    $required = $this->getJson('/report')->assertStatus(402);

    $this->getJson('/report', $this->x402Payment($required))->assertOk();

    X402::assertSettled();
    X402::assertSettledFor('/report', '0.01');
});

it('rejects an underpayment', function () {
    X402::fake();

    $required = $this->getJson('/report');

    $this->getJson('/report', $this->x402Payment($required, amount: '1'))->assertStatus(402);

    X402::assertNotCharged();
});

it('does not charge our own monitoring', function () {
    X402::fake();

    config()->set('x402.bypass.ips', ['127.0.0.1']);

    $this->getJson('/report')->assertOk();

    X402::assertNotCharged();
});
```

Facilitator assertions: `assertVerified()`, `assertSettled()`, `assertSettledFor()`, `assertNotCharged()`,
`assertNothingVerified()` — each accepting an optional expected count.

Trait helpers: `x402Payment()` builds the request headers, `x402Payload()` the payload object, `x402Requirements()`
decodes a 402's `PAYMENT-REQUIRED` header and `x402Settlement()` decodes a receipt. Pass `amount:` to underpay,
`overrides:` to tamper with the authorization (an expired `validBefore`, a redirected `to`), `payer:` to pay from
another address and `version: 1` to send a legacy payload.

Drive the failure paths too:

```php
X402::fake()->rejectVerification(ErrorCode::InsufficientFunds);
X402::fake()->rejectSettlement(ErrorCode::InvalidTransactionState);
X402::fake()->pendingSettlement('0xbroadcast');
```

The package's own test suite covers over 90% of `src/`, including golden fixtures taken straight from the specification.

## Events

| Event | Fired when |
| --- | --- |
| `PaymentRequired` | a protected route was requested without a usable payment |
| `PaymentVerified` | a facilitator confirmed the authorization; nothing charged yet |
| `PaymentSettled` | funds have moved to your wallet |
| `PaymentFailed` | rejected at verification, failed at settlement, or the facilitator failed |
| `PaymentObserved` | observe mode: what would have been charged |

Every event carries a `PaymentContext` with the route, resource URL, price, atomic amount, asset, network, payer and
request id:

```php
Event::listen(PaymentSettled::class, function (PaymentSettled $event): void {
    $event->context->route->label();   // "GET /report"
    $event->context->amountUsd();      // "0.01"
    $event->context->payer();          // "0x857b…6b66"
    $event->transaction();             // "0x1234…"
});
```

`PaymentFailed::servedWithoutPayment()` tells you whether a response was already sent — the one case that needs a human.

## Payment records

```bash
php artisan vendor:publish --tag=x402-migrations
php artisan migrate
```

```php
use Requestway\X402\Models\X402Payment;

X402Payment::query()->settled()->forRoute('reports.show')->between($from, $to)->sum('amount_atomic');
X402Payment::query()->failed()->latest()->take(20)->get();
X402Payment::query()->forPayer('0x857b06519E91e3A54538791bDbb0E22373e36b66')->count();
```

Columns: route, method, resource URL, `amount_atomic`, `amount_usd`, asset, `asset_decimals`, network, payer, `pay_to`,
`transaction_hash`, status, `failure_reason`, facilitator, mode, `request_id`, `meta`, timestamps. Atomic amounts are
stored as strings because they routinely exceed a 64-bit integer.

Recording never affects a payment: if the database is unavailable the write is reported to your exception handler and the
payment still succeeds. Set `X402_DATABASE=false` to turn it off.

Payloads that could not be decoded at all are logged but **not** stored, so unauthenticated noise cannot grow the table;
real payment attempts that were rejected are stored, because those are worth seeing. Set `X402_RECORD_MALFORMED=true`
while debugging a client that cannot form a valid payment.

## Discovery

```
GET /.well-known/x402
```

```json
{
  "x402Version": 2,
  "items": [
    {
      "resource": "https://api.example.com/market-data",
      "type": "http",
      "x402Version": 2,
      "method": "GET",
      "accepts": [
        {
          "scheme": "exact",
          "network": "eip155:8453",
          "amount": "10000",
          "asset": "0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913",
          "payTo": "0x209693Bc6afc0C5328bA36FaF03C514EF312287C",
          "maxTimeoutSeconds": 60,
          "extra": { "name": "USD Coin", "version": "2" }
        }
      ],
      "description": "Live market data",
      "mimeType": "application/json",
      "lastUpdated": "2026-10-10T12:00:00+00:00"
    }
  ],
  "pagination": { "limit": 1, "offset": 0, "total": 1 },
  "service": { "name": "Example Data", "tags": ["finance"] }
}
```

Generated from your registered routes, so it cannot drift from what you actually charge. The payload reuses the shape of
the x402 discovery API, so any client that can read a Bazaar listing can read this. Set `X402_DISCOVERY=false` to turn it
off, or `X402_DISCOVERY_PATH` to move it.

## Artisan commands

| Command | What it does |
| --- | --- |
| `x402:install` | publishes config and migration, prompts for wallet, network and facilitator, validates the address |
| `x402:routes` | table of every paid route, its price, networks and bypass rules (`--json` for machines) |
| `x402:test {uri}` | runs the full 402 → pay → 200 exchange with a fake facilitator and prints both halves with headers decoded |
| `x402:doctor` | checks config, wallet format, assets, facilitator reachability, replay cache and migrations |

`x402:test` also drives the failure paths: `--underpay`, `--fail-settlement`, `--network=`, `--payer=`, `--header=`.

## Security

**Replay protection.** Every payload is fingerprinted (`sha256` over network, asset and the signed scheme payload) and
claimed atomically in the cache *before* verification, so a replay never even reaches the facilitator. Claims are released
when verification or settlement fails — nothing was charged, so a client is entitled to retry — and retained for
`X402_REPLAY_TTL` (24 hours by default) after a success. **Use a cache store shared by every web node**; `x402:doctor`
warns when `array` or `file` is configured.

**Resource binding.** When a client echoes the resource it is paying for, the package requires it to match the current
request URL. A payload bought for a cheap endpoint cannot be presented at an expensive one.

**Requirements matching.** The client's `accepted` echo must reproduce every term the server advertised: scheme, network,
asset, `payTo` and `maxTimeoutSeconds`, plus every `extra` key. The amount must be at or above the required amount; an
underpayment is rejected locally with the protocol's own error code.

**Validity windows.** `validAfter` and `validBefore` are checked against the server clock with a small skew tolerance,
before any network call.

**Clean failures.** Malformed base64 or JSON gets a `400`, a rejected payment gets a `402`, an unreachable facilitator
gets a `502`. None of them produce a `500`, and none of them leak a stack trace.

**Logging.** Signatures and full payment payloads are never logged. Payer addresses are abbreviated (`0x857b…6b66`)
unless you set `X402_LOG_PAYER=true`.

**Caching.** 402 responses are sent `no-store, private`; paid responses get `private` so a shared cache cannot serve one
client's paid response to another.

**No keys on your server.** The package never needs a private key. It verifies what the payer signed and asks a
facilitator to broadcast it.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Testnet to mainnet checklist

- [ ] `php artisan x402:doctor` passes with no failures.
- [ ] `X402_PAY_TO` is a wallet you control on the **mainnet** you are switching to, and you have checked the address
      character by character. Payments are irreversible.
- [ ] `X402_NETWORK` is a mainnet (`eip155:8453` for Base). `x402:doctor` warns when it is.
- [ ] `X402_FACILITATOR_URL` is a **production** facilitator. `x402.org` is for testnets and will not settle mainnet
      payments.
- [ ] The facilitator's `/supported` lists your scheme and network — `x402:doctor` checks this for you.
- [ ] `X402_REPLAY_STORE` points at a cache shared by every web node (redis, memcached, dynamodb, database).
- [ ] `X402_REPLAY_TTL` is longer than the largest `maxTimeoutSeconds` you advertise.
- [ ] The `x402_payments` migration has run, so you have a record of every payment.
- [ ] You have run `php artisan x402:test` against each paid route, including `--underpay` and `--fail-settlement`.
- [ ] You have a listener on `PaymentFailed` that alerts you when `servedWithoutPayment()` is true.
- [ ] You have decided between `before_response` (safe) and `after_response` (fast), knowingly.
- [ ] `X402_MODE=enforce`.
- [ ] You have made one real payment end to end with a small amount and seen it in your wallet.

Consider spending a week in `X402_MODE=observe` on mainnet config first: you will see exactly what you would have
charged, without charging anyone.

## Configuration reference

Every setting is environment-driven. The defaults are safe.

| Variable | Default | Purpose |
| --- | --- | --- |
| `X402_MODE` | `enforce` | `enforce`, `observe` or `off` |
| `X402_PAY_TO` | — | wallet that receives payments |
| `X402_NETWORK` | `eip155:84532` | default network (CAIP-2 or alias) |
| `X402_ADDITIONAL_NETWORKS` | — | comma-separated extra networks to accept |
| `X402_FACILITATOR_URL` | `https://x402.org/facilitator` | facilitator base URL |
| `X402_FACILITATOR_TOKEN` | — | bearer token for the facilitator |
| `X402_FACILITATOR_AUTH` | — | class building signed auth headers per call |
| `X402_FACILITATOR_TIMEOUT` | `30` | per-request timeout, seconds |
| `X402_FACILITATOR_RETRIES` | `2` | retries for verify and supported, connection failures only |
| `X402_FACILITATOR_SETTLE_RETRIES` | `0` | retries for settle; leave at 0 |
| `X402_SETTLEMENT_MODE` | `before_response` | `before_response` or `after_response` |
| `X402_MAX_TIMEOUT_SECONDS` | `60` | cap on the advertised `maxTimeoutSeconds` |
| `X402_ON_FACILITATOR_ERROR` | `fail_closed` | `fail_closed` or `fail_open` |
| `X402_DEFAULT_PRICE` | — | price for routes that do not set one |
| `X402_FREE_FOR_AUTH` | `false` | exempt signed-in users everywhere |
| `X402_BYPASS_IPS` | — | comma-separated IPs and CIDR ranges |
| `X402_API_KEY_HEADER` | `X-API-Key` | header carrying a bypass key |
| `X402_API_KEYS` | — | comma-separated bypass keys |
| `X402_REPLAY_STORE` | app default | cache store for replay claims |
| `X402_REPLAY_TTL` | `86400` | how long a used payment is remembered |
| `X402_DATABASE` | `true` | record payments |
| `X402_TABLE` | `x402_payments` | table name |
| `X402_RECORD_OBSERVED` | `true` | store observe-mode rows |
| `X402_RECORD_MALFORMED` | `false` | store undecodable payloads |
| `X402_DISCOVERY` | `true` | serve the discovery endpoint |
| `X402_DISCOVERY_PATH` | `.well-known/x402` | where to serve it |
| `X402_SERVICE_NAME` | `APP_NAME` | service name for agents and directories |
| `X402_LOG_PAYER` | `false` | log full payer addresses |
| `X402_PLATFORM_KEY` | — | Requestway project key; enables reporting (see [below](#requestway-dashboard)) |
| `X402_PLATFORM_URL` | `https://api.requestway.com/x402/events` | where reports are posted |
| `X402_PLATFORM_QUEUE` | default queue | queue for the report job |
| `X402_PLATFORM_QUEUE_CONNECTION` | default connection | queue connection for the report job |
| `X402_PLATFORM_TIMEOUT` | `5` | seconds before a report POST gives up |

Publish the fully commented config with `php artisan vendor:publish --tag=x402-config`.

## Requestway dashboard

[Requestway](https://requestway.com) is a free analytics dashboard for x402 APIs, from the authors of this package.
It sits beside your payments, never in them: the package tells it the outcome of each paid request after your API has
answered, and Requestway shows you what that adds up to.

- **Earnings per route**: daily revenue and the endpoints that earn it, over 7, 30 or 90 days.
- **Failed payments, grouped**: every failure by stage (`verify`, `settle`, …) and reason
  (`insufficient_funds`, `invalid_exact_evm_payload_signature`, …), most frequent first.
- **Demand you aren't charging for**: in [observe mode](#observe-mode), the requests that *would* have paid and what
  they would have been worth.
- **A request log**: thirty days of raw events with route, price, network and transaction.
- **Mainnet and testnet kept apart**: Base Sepolia test payments never show up as earnings, and the dashboard
  follows whichever network your app last reported from.
- **Live and test keys**: staging traffic stays out of your real numbers.
- **An endpoint tester**: Requestway calls your paid route like an agent would, reads the `402`, pays it with test USDC
  on Base Sepolia, checks the `PAYMENT-RESPONSE` receipt and waits for this package's report to arrive. It only ever
  pays on testnets; a mainnet-only route gets its `402` checked and nothing more.

### Connect it

1. Create a free project at [requestway.com](https://requestway.com/register) and copy its key.
2. Add it to `.env`. Use the `rw_test_` key on staging and the `rw_live_` key in production:

   ```env
   X402_PLATFORM_KEY=rw_live_xxxxxxxxxxxxxxxx
   ```

3. Run a queue worker. Reports are sent from a queued job, never from inside the request:

   ```bash
   php artisan queue:work
   ```

4. Make a paid (or observed) request, or run the endpoint tester from your project. Events appear within seconds.

That is all. Without a key, reporting is off and nothing is ever sent.

### How it behaves

- **Never in the way.** Events are buffered for the request and posted in one batch from a queued job after the
  response. A dispatch failure is reported to your exception handler and dropped. Reporting cannot slow down, fail or
  change a payment.
- **Bounded.** At most 100 events are buffered per request; each POST times out after `X402_PLATFORM_TIMEOUT`
  seconds.
- **Everything is configurable**: `X402_PLATFORM_URL`, `X402_PLATFORM_QUEUE`, `X402_PLATFORM_QUEUE_CONNECTION` and
  `X402_PLATFORM_TIMEOUT`, listed in the [configuration reference](#configuration-reference).

### What is sent

Each batch is a `POST` with `Authorization: Bearer <your key>` and a JSON body:

```json
{
  "source": "laravel-x402",
  "events": [
    {
      "type": "payment.settled",
      "at": "2026-10-11T12:00:00+00:00",
      "payment": {
        "route": "GET /market-data",
        "method": "GET",
        "resource": "https://api.example.com/market-data",
        "mode": "enforce",
        "price_usd": "0.01",
        "amount_atomic": "10000",
        "asset": "USDC",
        "network": "eip155:8453",
        "pay_to": "0x209693Bc6afc0C5328bA36FaF03C514EF312287C",
        "request_id": "9d2f…",
        "transaction": "0x1234…",
        "pending": false
      }
    }
  ]
}
```

Event types are `payment.settled`, `payment.failed` (with `stage` and `reason`) and `payment.observed`. **The payer's
address is never sent**, and Requestway discards query strings on arrival. Raw events are kept for 30 days; daily
totals for the life of the project. See the [privacy policy](https://requestway.com/privacy) for the full list.

### Your own backend instead

The `Reporter` contract is public, so you can send the same events anywhere:

```php
app()->singleton(Requestway\X402\Reporting\Reporter::class, MyOwnReporter::class);
```

### Learn more

- [Install guide](https://requestway.com/docs) for Laravel and Node, the endpoint tester and the ingest API
- [What is x402?](https://requestway.com/x402): the protocol explained for developers
- [Articles](https://requestway.com/articles): step-by-step guides, including
  [adding x402 to a Laravel API](https://requestway.com/articles/add-x402-payments-to-laravel),
  [testing on Base Sepolia](https://requestway.com/articles/test-x402-payments-base-sepolia),
  [observe mode](https://requestway.com/articles/x402-observe-mode) and
  [why x402 payments fail](https://requestway.com/articles/why-x402-payments-fail)
- On Node? [`@requestway/reporter`](https://github.com/requestway/reporter) reports the same events from
  `@x402/express`, `@x402/next` and `@x402/hono`

## FAQ

**Who holds the money?** You do. The payer signs an authorization to transfer USDC to the address in `X402_PAY_TO`, and
the facilitator broadcasts it. The funds go from their wallet to yours. This package never takes custody, never takes a
cut, and never needs a private key.

**Do I need a crypto wallet on my server?** No. You need an address, not a key. Nothing is signed server side.

**What does the facilitator see?** The signed payment and the payment requirements. It cannot change the amount or the
recipient — both are inside what the payer signed.

**What if the facilitator goes down?** Requests get a `502` and your resource is not served. Set
`X402_ON_FACILITATOR_ERROR=fail_open` to serve it for free instead.

**Can a client pay once and keep the response forever?** It can keep what it bought, like any purchase. It cannot reuse
the *payment*: the payload is claimed before verification and the token contract will not settle the same authorization
twice.

**What stops someone paying $0.01 for a $1.00 endpoint?** The amount is checked against what the route advertises before
the facilitator is called, and again inside the signed authorization. Underpayments are rejected with the protocol's
`invalid_exact_evm_payload_authorization_value_mismatch` code.

**Does this work with human users in a browser?** It works, but it is not what x402 is for. A browser hitting a paid
route gets a readable JSON 402 explaining the price. For humans, use normal checkout; for agents, use this.

**Which chains and tokens?** Any network and USD-pegged token your facilitator supports. The package ships the
specification's canonical USDC addresses for Base, Ethereum, Arbitrum, Polygon, Avalanche, Celo, Sei, Monad, XDC, Flare
and Arc (plus testnets), and you can add or override any of them in config.

**Can I use it outside Laravel?** The protocol core under `src/Core` has no framework dependencies and will be split out
as `requestway/x402-php` for WordPress and plain PHP.

**What is Requestway, and do I need it?** A free dashboard for x402 APIs from the authors of this package. It is
entirely optional: without `X402_PLATFORM_KEY` the package never contacts it. See [Requestway dashboard](#requestway-dashboard).

**Is this official?** No. It is an independent, spec-faithful implementation; see [docs/SPEC_NOTES.md](docs/SPEC_NOTES.md)
for exactly which version it implements and every place it makes a judgement call.

## Credits

Built on the [x402 protocol](https://github.com/x402-foundation/x402) by the x402 Foundation.

## License

MIT. See [LICENSE](LICENSE).
