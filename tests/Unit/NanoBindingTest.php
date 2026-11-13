<?php

declare(strict_types=1);

use Requestway\X402\Core\Support\NanoAddress;
use Requestway\X402\Core\Support\NanoBinding;

// The vectors published with the binding standard (docs/BINDING-STANDARD.md,
// "Test vectors"), so a payer in any language can check its encoder here.
const BINDING_PAY_TO = 'nano_1111111111111111111111111111111111111111111111111111hifc8npp';
const BINDING_AMOUNT = '50000000000000000000000000000';

it('computes the published order commitment', function (): void {
    expect(bin2hex(NanoBinding::commitment('order-0001', BINDING_PAY_TO, BINDING_AMOUNT)))
        ->toBe('86e75a339b908cc926a478c1a0e5eb5dd6fc612c1d3251eb661faa78135c36cf');
});

it('encodes the commitment as the published representative account', function (): void {
    $representative = NanoBinding::representative('order-0001', BINDING_PAY_TO, BINDING_AMOUNT);

    expect($representative)->toBe('nano_33q9dassq66es6mcay83n5kypqgpzjikr9bkc9ope9xch1borfphgehj4ozj')
        ->and(NanoAddress::isValid($representative))->toBeTrue();
});

it('commits to the order, the destination and the amount', function (): void {
    $base = NanoBinding::commitment('order-0001', BINDING_PAY_TO, BINDING_AMOUNT);

    expect(NanoBinding::commitment('order-0002', BINDING_PAY_TO, BINDING_AMOUNT))->not->toBe($base)
        ->and(NanoBinding::commitment('order-0001', 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3', BINDING_AMOUNT))->not->toBe($base)
        ->and(NanoBinding::commitment('order-0001', BINDING_PAY_TO, '50000000000000000000000000001'))->not->toBe($base);
});

it('encodes a public key as the address that decodes back to it', function (): void {
    expect(NanoAddress::fromPublicKey(str_repeat("\0", 32)))->toBe(BINDING_PAY_TO)
        ->and(NanoAddress::fromPublicKey((string) hex2bin('E89208DD038FBB269987689621D52292AE9C35941A7484756ECCED92A65093BA')))
        ->toBe('nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3');

    foreach (range(1, 20) as $ignored) {
        $key = random_bytes(32);

        expect(NanoAddress::publicKey(NanoAddress::fromPublicKey($key)))->toBe(strtoupper(bin2hex($key)));
    }
});

it('refuses to encode a key that is not 32 bytes', function (): void {
    expect(fn (): string => NanoAddress::fromPublicKey(str_repeat("\0", 31)))->toThrow(InvalidArgumentException::class);
});

it('accepts only opaque order ids long enough to be secret', function (string $orderId, bool $valid): void {
    expect(NanoBinding::isValidOrderId($orderId))->toBe($valid);
})->with([
    '22 base64url characters' => ['k3Jd9QwXv2LmP8sTzR4a-_', true],
    '32 hex characters' => ['0123456789abcdef0123456789abcdef', true],
    '128 characters' => [str_repeat('a', 128), true],
    'too short to be secret' => ['order-0001', false],
    'too long' => [str_repeat('a', 129), false],
    'a separator' => ['k3Jd9QwXv2LmP8sTzR4a|b', false],
    'whitespace' => ['k3Jd9QwXv2LmP8sTzR4a b', false],
    'trailing newline' => ["k3Jd9QwXv2LmP8sTzR4a-_\n", false],
]);
