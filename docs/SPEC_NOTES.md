# x402 Specification Notes

Implementation notes for `requestway/laravel-x402`, taken from the canonical
specification repository rather than from blog posts or secondary docs.

**Source of truth:** [`x402-foundation/x402`](https://github.com/x402-foundation/x402)
at commit `f8f8330` (2026-10-10), TypeScript reference packages at version
`2.28.0`.

Documents read:

| Document | Purpose |
| --- | --- |
| `specs/x402-specification-v2.md` | Core protocol v2: message schemas, facilitator API, error codes |
| `specs/transports-v2/http.md` | HTTP transport v2: header names, wire encoding |
| `specs/transports-v1/http.md` | HTTP transport v1: legacy header names and body format |
| `specs/schemes/exact/scheme_exact.md` | `exact` scheme semantics and payment flows |
| `specs/schemes/exact/scheme_exact_evm.md` | `exact` on EVM: EIP-3009, Permit2, ERC-7710 payloads |
| `specs/extensions/bazaar.md` | Discovery / marketplace listing shape |
| `DEFAULT_ASSETS.md` | Default asset table convention and USD-peg invariant |
| `typescript/packages/core/src/http/*` | Reference encoder/decoder and HTTP resource server |
| `typescript/packages/core/src/server/x402ResourceServer.ts` | Reference requirements matching |
| `typescript/packages/mechanisms/evm/src/defaultAssets.ts` | Canonical USDC/stablecoin addresses per chain |

## 1. Protocol version

This package implements **x402 protocol version 2** (`x402Version: 2`) for both
reading and writing, and reads **version 1** payment payloads for backwards
compatibility with older clients. Version 1 is never emitted.

## 2. HTTP headers

Protocol v2 (`specs/transports-v2/http.md`), all values **base64-encoded JSON**:

| Header | Direction | Carries |
| --- | --- | --- |
| `PAYMENT-REQUIRED` | server → client | `PaymentRequired` object |
| `PAYMENT-SIGNATURE` | client → server | `PaymentPayload` object |
| `PAYMENT-RESPONSE` | server → client | `SettlementResponse` object |

Protocol v1 (read-only support):

| Header | Direction | Carries |
| --- | --- | --- |
| `X-PAYMENT` | client → server | v1 `PaymentPayload` object |
| `X-PAYMENT-RESPONSE` | server → client | `SettlementResponse` object |

The v1 `PaymentRequired` object lived in the 402 **response body**; in v2 the
canonical location is the `PAYMENT-REQUIRED` **header**. The reference
implementation sends `{}` as the v2 body.

Base64 is standard (not URL-safe) with padding, matching `safeBase64Encode` in
`@x402/core`. The decoder accepts unpadded and URL-safe input as well, since
some clients emit it; it rejects anything else with a 400.

## 3. Message schemas

### `PaymentRequired`

```json
{
  "x402Version": 2,
  "error": "PAYMENT-SIGNATURE header is required",
  "resource": {
    "url": "https://api.example.com/premium-data",
    "description": "Access to premium market data",
    "mimeType": "application/json",
    "serviceName": "Example Market Data",
    "tags": ["market-data", "finance"],
    "iconUrl": "https://api.example.com/icon.png"
  },
  "accepts": [ /* PaymentRequirements */ ],
  "extensions": {}
}
```

`resource.serviceName`: printable ASCII, max 32 characters. `resource.tags`:
max 5 entries, each printable ASCII max 32 characters. `resource.iconUrl`: max
2048 characters. This package truncates/validates these on the way out so an
over-long value can never produce an invalid header.

### `PaymentRequirements`

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `scheme` | string | yes | `exact` |
| `network` | string | yes | CAIP-2, e.g. `eip155:8453` |
| `amount` | string | yes | **atomic units**, decimal string |
| `asset` | string | yes | token contract address |
| `payTo` | string | yes | recipient address |
| `maxTimeoutSeconds` | number | yes | payment completion window |
| `extra` | object | no | reserved keys `assetTransferMethod`, `paymentFlow`; for EIP-3009 the EIP-712 domain `name` and `version` are **required** |

Note the v1 → v2 rename: `maxAmountRequired` became `amount`, and the
per-requirement `resource` / `description` / `mimeType` / `outputSchema` fields
moved into the top-level `resource` (`ResourceInfo`) object.

### `PaymentPayload`

```json
{
  "x402Version": 2,
  "resource": { "url": "...", "description": "...", "mimeType": "..." },
  "accepted": { /* the PaymentRequirements the client chose */ },
  "payload": { /* scheme-specific */ },
  "extensions": {}
}
```

`resource` and `extensions` are optional; `accepted` and `payload` are required.
For `exact` on EVM with the default `eip3009` transfer method, `payload` is:

```json
{
  "signature": "0x…65 bytes…",
  "authorization": {
    "from": "0x…", "to": "0x…", "value": "10000",
    "validAfter": "1740672089", "validBefore": "1740672154",
    "nonce": "0x…32 bytes…"
  }
}
```

The v1 payload shape is flat: `{ x402Version: 1, scheme, network, payload }`
with no `accepted` and no `resource`.

### `SettlementResponse` (facilitator `/settle` response)

| Field | Type | Required |
| --- | --- | --- |
| `success` | bool | yes |
| `transaction` | string | yes (empty string when nothing was broadcast) |
| `network` | string | yes |
| `errorReason` | string | no |
| `payer` | string | no |
| `amount` | string | no |
| `extensions` | object | no |

The reference client also tolerates `errorMessage` and `extra`; both are kept.

### `VerifyResponse` (facilitator `/verify` response)

`isValid` (bool, required), `invalidReason`, `invalidMessage`, `payer`,
`extensions`, `extra` (all optional).

## 4. Network identifiers

CAIP-2 `namespace:reference`. The package stores and emits CAIP-2 only, and
accepts the v1 legacy aliases (`base`, `base-sepolia`, `avalanche`,
`avalanche-fuji`) as **input** to config, normalising them to CAIP-2:

| Alias | CAIP-2 |
| --- | --- |
| `base` | `eip155:8453` |
| `base-sepolia` | `eip155:84532` |
| `ethereum` | `eip155:1` |
| `avalanche` | `eip155:43114` |
| `avalanche-fuji` | `eip155:43113` |
| `polygon` | `eip155:137` |
| `arbitrum` | `eip155:42161` |

## 5. Facilitator API

| Endpoint | Method | Request | Response |
| --- | --- | --- | --- |
| `/verify` | POST | `{ x402Version, paymentPayload, paymentRequirements }` | `VerifyResponse` |
| `/settle` | POST | `{ x402Version, paymentPayload, paymentRequirements }` | `SettlementResponse` |
| `/supported` | GET | — | `{ kinds: [{ x402Version, scheme, network, extra? }], extensions: [], signers: {} }` |

`x402Version` at the top level of the verify/settle body is the **payload's**
version, matching `HTTPFacilitatorClient` in the reference.

The reference default per-request timeout is 90 seconds; this package defaults
to 30 and caps at a configurable maximum, because a Laravel request holding a
worker for 90 seconds is rarely what an operator wants. Both are configurable.

The reference client takes `createAuthHeaders()` keyed by path, because a
facilitator may sign each request rather than issuing a long-lived token (the
Coinbase CDP facilitator signs over the request path). This package mirrors that
with the `FacilitatorAuth` contract, whose `headers(string $operation)` receives
`verify`, `settle` or `supported`; static headers remain available for
token-based facilitators.

The facilitator may return an `EXTENSION-RESPONSES` header. It is a
**server-internal sidechannel** and MUST NOT be forwarded to buyers — the
package strips `extensionResponses` before encoding `PAYMENT-RESPONSE`.

## 6. Error codes

Taken verbatim from §9 of the core spec:

`insufficient_funds`, `invalid_exact_evm_payload_authorization_valid_after`,
`invalid_exact_evm_payload_authorization_valid_before`,
`invalid_exact_evm_payload_authorization_value_mismatch`,
`invalid_exact_evm_payload_signature`,
`invalid_exact_evm_payload_recipient_mismatch`, `invalid_network`,
`invalid_payload`, `invalid_payment_requirements`, `invalid_scheme`,
`unsupported_scheme`, `invalid_x402_version`, `invalid_transaction_state`,
`unexpected_verify_error`, `unexpected_settle_error`, `settlement_pending`.

`settlement_pending` is explicitly **non-terminal**: the transaction was
broadcast but confirmation could not be established. A response carrying it
MUST have a non-empty `transaction`. The reference retries settle exactly once
on this reason and never more; this package does the same and records the
payment as `pending` rather than `failed` when the retry also comes back
pending.

HTTP status mapping (`specs/transports-v2/http.md`):

| Condition | Status |
| --- | --- |
| Payment required | 402 |
| Malformed payment payload | 400 |
| Verification or settlement failed | 402 |
| `permit2_allowance_required` | 412 |
| Server error | 500 |

## 7. Payment flows

A mechanism declares a flow via `extra.paymentFlow`:

| Flow | Ordering |
| --- | --- |
| `authorization` (default) | verify → resource → settle → respond |
| `upfront` | settle → resource → respond |
| `escrow` | settle → resource → settle → respond |

Invariant: **at least one check runs before the resource executes.**

This package implements the `authorization` flow in two settlement modes:

* `before_response` (default) — verify → run the route → settle → respond.
  Equivalent to the spec's `authorization` flow. A settlement failure means the
  response body is discarded and a 402 is returned, so a client is never served
  a paid resource without a committed payment.
* `after_response` — verify → run the route → respond → settle in Laravel's
  `terminate()` hook. Lower latency, at the cost of a small window where a
  response has been served but settlement later fails. Failures fire
  `PaymentFailed` and are logged and persisted.

Both modes verify before the route runs, satisfying the invariant. `upfront`
and `escrow` are not implemented; a requirement advertising a `paymentFlow`
this package does not implement is rejected at configuration time rather than
silently downgraded.

## 8. Requirements matching

The reference server (`findMatchingRequirements` →
`paymentRequirementsMatchAccepted`) requires that every server-declared core
field of the chosen `accepts` entry is reproduced exactly in
`payload.accepted`, and that every server-declared `extra` key is present with
an equal value (clients may add extra keys, not remove or change them).

This package follows that, with these deliberate, documented differences:

1. **Addresses compare case-insensitively.** `asset` and `payTo` are compared
   lowercased, because EIP-55 checksum casing is cosmetic and clients
   round-trip it inconsistently.
2. **`amount` is accepted when it is greater than or equal to the required
   amount.** The spec's `exact` scheme requires equality and a facilitator will
   reject a mismatch, so this can only ever be more conservative than the
   reference from the server's point of view — it never accepts an underpayment.
   An underpayment is rejected locally with
   `invalid_exact_evm_payload_authorization_value_mismatch`.
3. **`resource.url` is checked when present.** The v2 payload's `resource` is
   optional. When a client sends it, the package requires it to match the
   current request URL, which closes off replaying a payload bought for one
   endpoint against another. When it is absent, the binding comes from the
   `accepted` echo and the facilitator's own checks.

For a v1 payload there is no `accepted` object, so — as in the reference — the
candidate requirement is matched on `scheme` and `network`, and the inner
`authorization.to` / `authorization.value` are then validated against the
server's `payTo` and `amount`.

## 9. Replay protection

The spec lists the EIP-3009 nonce, on-chain nonce consumption, the validity
window, and signature verification as its layers (§10.1). All of those live
with the facilitator and the token contract.

This package adds a **resource-server-side** layer, which the spec does not
mandate but which the `exact` scheme explicitly asks for in its
"duplicate delivery" requirement: a single payment must not yield several
resources. Each payload is reduced to a stable key —
`sha256(network | asset | canonical-json(payload))` — and claimed atomically in
the configured cache store with `add()` before verification. A payload whose
key is already claimed is rejected with `invalid_payload`.

The claim is **released** when verification or settlement fails, because
nothing was charged and a client is entitled to retry; it is retained for the
configured TTL after a successful settlement. The TTL defaults to 24 hours,
which must exceed the longest `maxTimeoutSeconds` a route advertises.

## 10. Amount arithmetic

`amount` is a decimal string in **atomic units** of `asset`. USD prices are
converted with the asset's `decimals` (USDC: 6) using string arithmetic only —
never floats, and without requiring `ext-bcmath`, which is absent from many
shared hosts. Sub-atomic precision rounds **up**, so a route can never be
underpriced by rounding: `$0.0000001` on a 6-decimal asset becomes `1` atomic
unit, not `0`.

## 11. Discovery

The spec's discovery surface is the facilitator-hosted Bazaar
(`GET /discovery/resources`, §8), not an endpoint on the resource server.
There is **no `/.well-known/x402` in the specification.**

This package serves one anyway, because an agent that has a URL and wants to
know its price has nowhere else to look. The response reuses the spec's
`DiscoveredResource` shape exactly (`x402Version`, `items[]` of
`{ resource, type, x402Version, accepts, lastUpdated }`, `pagination`), so a
client that can read a Bazaar listing can read this with no extra code. The
endpoint is toggleable and off-by-default-safe.

## 12. Default assets

Addresses are taken from `typescript/packages/mechanisms/evm/src/defaultAssets.ts`
and are fully overridable from config. All entries in the table are USD-pegged
by the spec's invariant. The EIP-712 domain `name` and `version` are shipped
alongside each address because `exact`/`eip3009` **requires** them in
`extra` — a client cannot construct a signature without them, and getting them
wrong produces a signature that verifies against nothing.

Shipped networks: Base, Base Sepolia, Ethereum, Avalanche, Avalanche Fuji,
Polygon, Arbitrum One, Arbitrum Sepolia, Celo, Celo Sepolia, Sei, Sei testnet,
Monad, Monad testnet, XDC, XDC Apothem, Flare, Arc, Arc testnet.

The package defaults to **Base Sepolia** (`eip155:84532`) so that an
unconfigured install cannot move real money.

## 13. Differences from the task brief

The brief was written against an earlier reading of the protocol. Where it
differs from the specification, the specification wins:

| Brief | Specification | Resolution |
| --- | --- | --- |
| "`resource` in the payload" | `resource` is optional in v2 and lives at the payload's top level, not inside `accepted` | Checked when present (§8.3) |
| "amount, asset, payTo, network, resource, expiry validated against expected requirements" | v2 adds the `accepted` echo, which must reproduce the server's advertised requirement | Full `accepted` matching implemented, plus all the listed field checks |
| 402 body with the same info | v2 puts everything in the header; the body "is a server implementation concern" | Header is canonical; a v2-shaped JSON body with a human-readable `error` is sent as well, since the spec leaves the body to the server |
| `/.well-known/x402` discovery | Not in the spec | Implemented using the spec's `DiscoveredResource` shape (§11) |
| Verify then settle, then run the controller | The `authorization` flow settles **after** the resource runs | `before_response` runs the route, then settles, then responds — so a failing route does not charge the client (§7) |

## 14. Nano (opt-in, outside the specification)

`nano:mainnet` is not in the specification's network table and has no
mechanism document. This package supports it only when an operator sets
`x402.nano.rpc_url`; without that, nothing in this section applies and every
network, `nano:mainnet` included, goes to the HTTP facilitator unchanged.

* **Identifier.** `nano:mainnet`, CAIP-2 shaped; `nano` is accepted as an
  alias. Addresses are `nano_` or legacy `xrb_` with the Blake2b-40 checksum
  verified (implemented in `Core\Support\Blake2b`, because ext-sodium cannot
  produce a 5-byte digest).
* **Asset.** No default asset and no USD peg. The operator configures
  `assets.nano:mainnet` with `decimals` 30 (raw units), its own `pay_to`, and
  `units_per_usd`; the route price stays in USD and is converted at that rate,
  rounding up (§10). EIP-712 `name`/`version` are not emitted in `extra`.
* **Payload.** Exactly `{ "blockHash": "<64 uppercase hex>" }`. The client
  pays first by publishing a send block; there is no authorization to sign or
  broadcast. Any other spelling is refused, because §9 keys on the payload as
  sent and a second spelling of one block would buy a second delivery.
* **Verification.** `Core\Facilitator\NanoFacilitator` makes one `block_info`
  RPC call and requires: `confirmed`, `subtype` `send`, `link_as_account`
  equal to `payTo` (compared by public key), and `amount` at or above the
  required raw amount (the same ≥ relaxation as §8.2). An unknown block is
  `invalid_payload`; an unreachable or erroring node is a facilitator failure,
  handled by `settlement.on_facilitator_error` like any other.
* **Settlement.** A confirmed Nano send is final, so `settle` re-reads the
  block and returns its hash as `transaction`. No key is held and no funds are
  moved.
* **Replay.** A confirmed block never expires, so duplicate delivery is
  stopped by the §9 `ReplayGuard` alone. To keep that sound, blocks whose node
  `local_timestamp` is older than `replay.ttl` (or absent) are refused, so the
  guard still holds every block that can pass. A node that has just
  bootstrapped dates old blocks to its sync time; point this at a node that
  has been running for longer than the replay TTL.
