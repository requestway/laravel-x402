<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Core\Errors\ErrorCode;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorConnectionException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorResponseException;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Facilitator\NanoFacilitator;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Protocol\V2Codec;
use Requestway\X402\Core\Support\Base64;
use Requestway\X402\Core\Support\FrozenClock;
use Requestway\X402\Core\Support\Json;
use Requestway\X402\Facilitators\HttpFacilitator;
use Requestway\X402\Facilitators\RoutingFacilitator;
use Requestway\X402\Tests\TestCase;

const NANO_PAY_TO = 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3';
const NANO_PAYER = 'nano_16aj46aj46aj46aj46aj46aj46aj46aj46aj46aj46aj46aj46ajbtsyew7c';
const NANO_NODE = 'https://node.test/rpc';
const NANO_HASH = '991CF190094C00F0B68E2E5F75F6BEE95A2E0BD93CEAA4A6734DB9F19B728948';
const NANO_NOW = 1_760_000_000;

function nanoRequirements(string $amount = '10000000000000000000000000000'): PaymentRequirements
{
    return new PaymentRequirements('exact', 'nano:mainnet', $amount, 'XNO', NANO_PAY_TO, 60);
}

function nanoPayload(): PaymentPayload
{
    return new PaymentPayload(2, nanoRequirements(), ['blockHash' => NANO_HASH]);
}

/**
 * A block_info response for a confirmed send of 0.01 XNO to NANO_PAY_TO.
 *
 * @param array<string, mixed> $overrides
 * @param array<string, mixed> $contents
 * @return array<string, mixed>
 */
function nanoBlock(array $overrides = [], array $contents = []): array
{
    return array_merge([
        'block_account' => NANO_PAYER,
        'amount' => '10000000000000000000000000000',
        'balance' => '5000000000000000000000000000000',
        'height' => '42',
        'local_timestamp' => (string) (NANO_NOW - 30),
        'confirmed' => 'true',
        'contents' => array_merge([
            'type' => 'state',
            'account' => NANO_PAYER,
            'link_as_account' => NANO_PAY_TO,
        ], $contents),
        'subtype' => 'send',
    ], $overrides);
}

/**
 * @param array<string, mixed>|Throwable $response
 */
function nanoFacilitator(array|Throwable $response, ?array &$calls = null): NanoFacilitator
{
    $calls = [];

    return new NanoFacilitator(
        rpc: function (array $body) use ($response, &$calls): array {
            $calls[] = $body;

            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        },
        maxAgeSeconds: 86400,
        clock: new FrozenClock(NANO_NOW),
    );
}

function nanoVerify(array|Throwable $response, ?PaymentPayload $payload = null, ?PaymentRequirements $requirements = null): Requestway\X402\Core\Messages\VerifyResponse
{
    return nanoFacilitator($response)->verify($payload ?? nanoPayload(), $requirements ?? nanoRequirements(), new ResourceInfo('https://api.test/x'));
}

it('verifies a confirmed send block to payTo with a single block_info call', function (): void {
    $facilitator = nanoFacilitator(nanoBlock(), $calls);

    $verification = $facilitator->verify(nanoPayload(), nanoRequirements(), new ResourceInfo('https://api.test/x'));

    expect($verification->isValid)->toBeTrue()
        ->and($verification->payer)->toBe(NANO_PAYER)
        ->and($calls)->toBe([['action' => 'block_info', 'json_block' => 'true', 'hash' => NANO_HASH]]);
});

it('settles by re-reading the block, never by broadcasting', function (): void {
    $facilitator = nanoFacilitator(nanoBlock(['amount' => '20000000000000000000000000000']), $calls);

    $settlement = $facilitator->settle(nanoPayload(), nanoRequirements(), new ResourceInfo('https://api.test/x'));

    expect($settlement->success)->toBeTrue()
        ->and($settlement->transaction)->toBe(NANO_HASH)
        ->and($settlement->network)->toBe('nano:mainnet')
        ->and($settlement->payer)->toBe(NANO_PAYER)
        ->and($settlement->amount)->toBe('20000000000000000000000000000')
        ->and(array_column($calls, 'action'))->toBe(['block_info']);
});

it('rejects a payment the block does not cover', function (array $block, ErrorCode $reason, string $message): void {
    $verification = nanoVerify($block);

    expect($verification->isValid)->toBeFalse()
        ->and($verification->invalidReason)->toBe($reason->value)
        ->and($verification->invalidMessage)->toContain($message);
})->with([
    'underpaid' => [nanoBlock(['amount' => '9999999999999999999999999999']), ErrorCode::ValueMismatch, 'less than'],
    'unconfirmed' => [nanoBlock(['confirmed' => 'false']), ErrorCode::InvalidTransactionState, 'not confirmed'],
    'wrong destination' => [nanoBlock(contents: ['link_as_account' => 'nano_1111111111111111111111111111111111111111111111111111hifc8npp']), ErrorCode::RecipientMismatch, 'recipient'],
    'receive block' => [nanoBlock(['subtype' => 'receive']), ErrorCode::InvalidPayload, 'not a send block'],
    'older than the replay window' => [nanoBlock(['local_timestamp' => (string) (NANO_NOW - 86401)]), ErrorCode::InvalidPayload, 'replay window'],
    'no local timestamp' => [nanoBlock(['local_timestamp' => '0']), ErrorCode::InvalidPayload, 'replay window'],
    'unknown block' => [['error' => 'Block not found'], ErrorCode::InvalidPayload, 'does not know'],
]);

it('accepts a block whose destination is written with the legacy xrb_ prefix', function (): void {
    $verification = nanoVerify(nanoBlock(contents: ['link_as_account' => 'xrb_'.substr(NANO_PAY_TO, 5)]));

    expect($verification->isValid)->toBeTrue();
});

it('rejects a malformed block hash without calling the node', function (array $payload): void {
    $facilitator = nanoFacilitator(nanoBlock(), $calls);

    $settlement = $facilitator->settle(new PaymentPayload(2, nanoRequirements(), $payload), nanoRequirements(), new ResourceInfo('https://api.test/x'));

    expect($settlement->success)->toBeFalse()
        ->and($settlement->errorReason)->toBe(ErrorCode::InvalidPayload->value)
        ->and($calls)->toBe([]);
})->with([
    'short' => [['blockHash' => 'ABCDEF']],
    'not hex' => [['blockHash' => str_repeat('Z', 64)]],
    'not a string' => [['blockHash' => 123]],
    // A second spelling of one block would get past ReplayGuard.
    'lowercase' => [['blockHash' => strtolower(NANO_HASH)]],
    'extra key' => [['blockHash' => NANO_HASH, 'nonce' => '1']],
]);

it('refuses requirements for another network', function (): void {
    $verification = nanoVerify(nanoBlock(), requirements: new PaymentRequirements('exact', 'eip155:8453', '1', 'XNO', NANO_PAY_TO, 60));

    expect($verification->invalidReason)->toBe(ErrorCode::InvalidNetwork->value);
});

it('throws a facilitator exception when the node fails, rather than rejecting the payment', function (array|Throwable $response, string $exception): void {
    expect(fn () => nanoVerify($response))->toThrow($exception);
})->with([
    'rpc error' => [['error' => 'Unable to parse JSON'], FacilitatorResponseException::class],
    'transport error' => [new RuntimeException('Connection refused'), FacilitatorConnectionException::class],
    'facilitator error' => [new FacilitatorResponseException('boom'), FacilitatorResponseException::class],
]);

it('advertises exact on nano:mainnet', function (): void {
    expect(nanoFacilitator([])->supported()->supports('exact', 'nano:mainnet'))->toBeTrue();
});

it('keeps the HTTP facilitator when no Nano node is configured', function (): void {
    expect(app(Facilitator::class))->toBeInstanceOf(HttpFacilitator::class);
});

describe('with a Nano node configured', function (): void {
    beforeEach(function (): void {
        config()->set('x402.nano.rpc_url', NANO_NODE);
        config()->set('x402.assets.nano', [
            'address' => 'XNO', 'decimals' => 30, 'symbol' => 'XNO', 'name' => 'Nano',
            'pay_to' => NANO_PAY_TO, 'units_per_usd' => '1',
        ]);
        app()->forgetInstance(Facilitator::class);
    });

    it('routes nano:mainnet to the node and every other network to the facilitator', function (): void {
        Http::fake([
            NANO_NODE => Http::response(nanoBlock(['local_timestamp' => (string) time()])),
            TestCase::FACILITATOR.'/verify' => Http::response(['isValid' => true, 'payer' => TestCase::PAYER]),
        ]);

        $facilitator = app(Facilitator::class);
        $base = new PaymentRequirements('exact', 'eip155:8453', '10000', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', TestCase::PAY_TO, 60);

        expect($facilitator)->toBeInstanceOf(RoutingFacilitator::class)
            ->and($facilitator->for($base))->toBeInstanceOf(HttpFacilitator::class)
            ->and($facilitator->verify(new PaymentPayload(2, $base, ['signature' => '0x']), $base, new ResourceInfo('https://api.test/x'))->payer)->toBe(TestCase::PAYER)
            ->and($facilitator->verify(nanoPayload(), nanoRequirements(), new ResourceInfo('https://api.test/x'))->payer)->toBe(NANO_PAYER);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === NANO_NODE && $request['action'] === 'block_info');
    });

    it('adds nano:mainnet to what the facilitator supports', function (): void {
        Http::fake([TestCase::FACILITATOR.'/supported' => Http::response([
            'kinds' => [['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532']],
        ])]);

        expect(app(Facilitator::class)->supported()->networks())->toBe(['eip155:84532', 'nano:mainnet']);
    });

    it('reports a node that answers with an HTTP error as a facilitator failure', function (): void {
        Http::fake([NANO_NODE => Http::response('down', 503)]);

        expect(fn () => app(Facilitator::class)->verify(nanoPayload(), nanoRequirements(), new ResourceInfo('https://api.test/x')))
            ->toThrow(FacilitatorException::class, 'HTTP 503');
    });

    it('charges a nano route end to end, and serves a block only once', function (): void {
        Http::fake([NANO_NODE => Http::response(nanoBlock(['local_timestamp' => (string) time()]))]);
        Route::get('/nano', fn (): array => ['data' => 'secret'])->middleware('x402:0.01,networks=base|nano');

        $accepts = paymentRequired($this->getJson('/nano'))->accepts;

        expect($accepts)->toHaveCount(2)
            ->and($accepts[0]->toArray())->toBe([
                'scheme' => 'exact',
                'network' => 'eip155:8453',
                'amount' => '10000',
                'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'payTo' => TestCase::PAY_TO,
                'maxTimeoutSeconds' => 60,
                'extra' => ['name' => 'USD Coin', 'version' => '2'],
            ])
            ->and($accepts[1]->toArray())->toBe([
                'scheme' => 'exact',
                'network' => 'nano:mainnet',
                'amount' => '10000000000000000000000000000',
                'asset' => 'XNO',
                'payTo' => NANO_PAY_TO,
                'maxTimeoutSeconds' => 60,
            ]);

        $header = [V2Codec::PAYMENT_SIGNATURE => Base64::encode(Json::encode(
            (new PaymentPayload(2, $accepts[1], ['blockHash' => NANO_HASH]))->toArray()
        ))];

        $paid = $this->getJson('/nano', $header);

        $paid->assertOk()->assertExactJson(['data' => 'secret']);

        expect(settlement($paid)->transaction)->toBe(NANO_HASH)
            ->and(settlement($paid)->payer)->toBe(NANO_PAYER);

        $this->getJson('/nano', $header)->assertStatus(402);
    });
});
