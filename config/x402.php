<?php

declare(strict_types=1);

use Requestway\X402\Core\Networks\Network;

return [

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | enforce — charge for access to protected routes.
    | observe — never charge: log what would have been charged and add an
    |           X-X402-Would-Charge header, so you can measure agent demand
    |           before turning payments on.
    | off     — protected routes behave as if they were never protected.
    |
    */

    'mode' => env('X402_MODE', 'enforce'),

    /*
    |--------------------------------------------------------------------------
    | Wallet
    |--------------------------------------------------------------------------
    |
    | The address payments are made to. Funds move directly from the payer to
    | this wallet on chain; this package never holds or routes money.
    |
    */

    'pay_to' => env('X402_PAY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    |
    | CAIP-2 identifier of the default network, e.g. eip155:8453 for Base.
    | Friendly aliases ("base", "base-sepolia") are accepted and normalised.
    |
    | Defaults to Base Sepolia so that an unconfigured install cannot move
    | real money.
    |
    */

    'network' => env('X402_NETWORK', Network::BASE_SEPOLIA),

    /*
    |--------------------------------------------------------------------------
    | Additional accepted networks
    |--------------------------------------------------------------------------
    |
    | Every network listed here is advertised alongside the default one in the
    | 402 response, letting a client pay on whichever chain it holds funds on.
    |
    */

    'additional_networks' => array_values(array_filter(
        explode(',', (string) env('X402_ADDITIONAL_NETWORKS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | USD-pegged token to charge in, per network. The package ships the
    | canonical table from the x402 specification; anything you define here is
    | merged over it.
    |
    | "name" and "version" are the token contract's EIP-712 domain values. The
    | exact/eip3009 scheme requires them: a client cannot build a valid
    | signature without them, and wrong values produce a signature that
    | verifies against nothing.
    |
    */

    'assets' => [
        // 'eip155:8453' => [
        //     'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        //     'decimals' => 6,
        //     'symbol' => 'USDC',
        //     'name' => 'USD Coin',
        //     'version' => '2',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Facilitator
    |--------------------------------------------------------------------------
    |
    | The facilitator verifies signatures and settles payments on chain. It
    | never holds your funds. x402.org is for testnet development only — use a
    | production facilitator, or run your own, for mainnet.
    |
    | "settle_retries" defaults to 0 on purpose: a settle that timed out may
    | still have gone through, so retrying it risks a double charge.
    |
    */

    'facilitator' => [
        'url' => env('X402_FACILITATOR_URL', 'https://x402.org/facilitator'),

        'headers' => array_filter([
            'Authorization' => env('X402_FACILITATOR_TOKEN')
                ? 'Bearer '.env('X402_FACILITATOR_TOKEN')
                : null,
        ]),

        /*
        | Facilitators that sign each request rather than taking a long-lived
        | token — the Coinbase CDP facilitator among them — need headers built
        | per call. Point this at a class implementing
        | Requestway\X402\Core\Facilitator\FacilitatorAuth.
        */
        'auth' => env('X402_FACILITATOR_AUTH'),

        'timeout' => (int) env('X402_FACILITATOR_TIMEOUT', 30),

        'connect_timeout' => (int) env('X402_FACILITATOR_CONNECT_TIMEOUT', 5),

        'retries' => (int) env('X402_FACILITATOR_RETRIES', 2),

        'settle_retries' => (int) env('X402_FACILITATOR_SETTLE_RETRIES', 0),

        'retry_delay' => (int) env('X402_FACILITATOR_RETRY_DELAY', 250),

        'verify_ssl' => (bool) env('X402_FACILITATOR_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nano node
    |--------------------------------------------------------------------------
    |
    | Set an RPC URL to verify nano:mainnet payments against a Nano node you
    | run or trust instead of the facilitator. Add a nano:mainnet entry under
    | "assets" with its own pay_to and units_per_usd. Unset, nothing changes.
    |
    */

    'nano' => [
        'rpc_url' => env('X402_NANO_RPC_URL'),

        'timeout' => (int) env('X402_NANO_RPC_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    |
    | before_response — verify, run the route, settle, then respond. A failed
    |                   settlement means the client is not served.
    | after_response  — verify, run the route, respond, then settle in
    |                   terminate(). Lower latency, but a served response can
    |                   later fail to settle (PaymentFailed is fired).
    |
    | max_timeout_seconds caps the maxTimeoutSeconds advertised to clients.
    |
    */

    'settlement' => [
        'mode' => env('X402_SETTLEMENT_MODE', 'before_response'),

        'max_timeout_seconds' => (int) env('X402_MAX_TIMEOUT_SECONDS', 60),

        /*
        | What to do when the facilitator itself is unreachable or broken:
        | fail_closed returns 502 and serves nothing, fail_open serves the
        | route for free. Never fail open unless you can afford to.
        */
        'on_facilitator_error' => env('X402_ON_FACILITATOR_ERROR', 'fail_closed'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Price tiers
    |--------------------------------------------------------------------------
    |
    | Named USD prices usable as ->middleware('x402:premium').
    |
    */

    'tiers' => [
        'micro' => '0.001',
        'standard' => '0.01',
        'premium' => '0.10',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bypass rules
    |--------------------------------------------------------------------------
    |
    | Requests matching any enabled rule are served without payment.
    |
    | auth      — authenticated users. Off by default; opt a route in with
    |             ->middleware('x402:0.01,free-for-auth'), or set "always" to
    |             true to exempt signed-in users everywhere.
    | ips       — allowlisted addresses and CIDR ranges.
    | api_keys  — a shared secret in a header, for customers who already pay
    |             you some other way.
    | closure   — set at runtime with X402::bypassUsing(fn ($request) => ...).
    |
    */

    'bypass' => [
        'auth' => [
            'always' => (bool) env('X402_FREE_FOR_AUTH', false),
            'guards' => array_values(array_filter(
                explode(',', (string) env('X402_FREE_FOR_AUTH_GUARDS', ''))
            )),
        ],

        'ips' => array_values(array_filter(
            explode(',', (string) env('X402_BYPASS_IPS', ''))
        )),

        'api_keys' => [
            'header' => env('X402_API_KEY_HEADER', 'X-API-Key'),
            'keys' => array_values(array_filter(
                explode(',', (string) env('X402_API_KEYS', ''))
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay protection
    |--------------------------------------------------------------------------
    |
    | Each settled payload is claimed atomically in this cache store so the
    | same signed payment cannot buy the same resource twice. The TTL must be
    | longer than the largest maxTimeoutSeconds you advertise.
    |
    | Use a store shared by every web node — "array" and "file" are not.
    |
    */

    'replay' => [
        'store' => env('X402_REPLAY_STORE'),
        'ttl' => (int) env('X402_REPLAY_TTL', 86400),
        'prefix' => env('X402_REPLAY_PREFIX', 'x402:replay:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | Record every payment in the x402_payments table. Publish the migration
    | with: php artisan vendor:publish --tag=x402-migrations
    |
    */

    'database' => [
        'enabled' => (bool) env('X402_DATABASE', true),
        'connection' => env('X402_DATABASE_CONNECTION'),
        'table' => env('X402_TABLE', 'x402_payments'),
        'record_observed' => (bool) env('X402_RECORD_OBSERVED', true),

        /*
        | Payloads that could not be decoded at all are logged but not stored,
        | so unauthenticated noise cannot grow the table. Turn this on if you
        | are debugging a client that cannot form a valid payment.
        */
        'record_malformed' => (bool) env('X402_RECORD_MALFORMED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Serves a machine-readable catalogue of your paid routes so agents and
    | directories can find them. The payload reuses the shape of the x402
    | Bazaar discovery API, so any client that can read a Bazaar listing can
    | read this.
    |
    */

    'discovery' => [
        'enabled' => (bool) env('X402_DISCOVERY', true),
        'path' => env('X402_DISCOVERY_PATH', '.well-known/x402'),
        'middleware' => ['throttle:60,1'],
        'cache_ttl' => (int) env('X402_DISCOVERY_CACHE_TTL', 60),
        'service_name' => env('X402_SERVICE_NAME', env('APP_NAME')),
        'icon_url' => env('X402_ICON_URL'),
        'tags' => array_values(array_filter(
            explode(',', (string) env('X402_TAGS', ''))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional reporting
    |--------------------------------------------------------------------------
    |
    | Off unless X402_PLATFORM_KEY is set. When enabled, payment and observe
    | events are queued and posted in batches to a hosted dashboard. Reporting
    | never blocks a request and never fails one.
    |
    */

    'reporting' => [
        'enabled' => (bool) env('X402_PLATFORM_KEY', false),
        'url' => env('X402_PLATFORM_URL', 'https://api.requestway.com/x402/events'),
        'key' => env('X402_PLATFORM_KEY'),
        'queue' => env('X402_PLATFORM_QUEUE'),
        'connection' => env('X402_PLATFORM_QUEUE_CONNECTION'),
        'timeout' => (int) env('X402_PLATFORM_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for protected routes
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'price' => env('X402_DEFAULT_PRICE'),
        'description' => env('X402_DEFAULT_DESCRIPTION'),
        'mime_type' => env('X402_DEFAULT_MIME_TYPE', 'application/json'),
        'scheme' => 'exact',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Signatures and full payment payloads are never logged. Payer addresses
    | are abbreviated unless log_payer is true.
    |
    */

    'logging' => [
        'channel' => env('X402_LOG_CHANNEL'),
        'log_payer' => (bool) env('X402_LOG_PAYER', false),
    ],

];
