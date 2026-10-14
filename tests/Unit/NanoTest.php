<?php

declare(strict_types=1);

use Requestway\X402\Core\Assets\Asset;
use Requestway\X402\Core\Assets\AssetRegistry;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Money\Integers;
use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Support\Blake2b;
use Requestway\X402\Core\Support\NanoAddress;

const NANO_GENESIS = 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3';

it('normalizes the nano alias to nano:mainnet', function (): void {
    expect(Network::normalize('nano'))->toBe('nano:mainnet')
        ->and(Network::normalize('Nano'))->toBe('nano:mainnet')
        ->and(Network::isNano('nano:mainnet'))->toBeTrue()
        ->and(Network::isNano('eip155:8453'))->toBeFalse()
        ->and(Network::isEvm('nano:mainnet'))->toBeFalse()
        ->and(Network::isTestnet('nano'))->toBeFalse()
        ->and(Network::label('nano:mainnet'))->toBe('Nano');
});

it('computes BLAKE2b at any digest length', function (): void {
    // RFC 7693 appendix A.
    expect(bin2hex(Blake2b::hash('abc', 64)))->toBe(
        'ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d1'
        .'7d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923'
    );

    foreach (['', 'abc', str_repeat('x', 128), str_repeat('y', 300)] as $data) {
        expect(Blake2b::hash($data, 32))->toBe(sodium_crypto_generichash($data, '', 32));
    }

    expect(fn (): string => Blake2b::hash('abc', 0))->toThrow(InvalidArgumentException::class);
})->skip(! function_exists('sodium_crypto_generichash'), 'ext-sodium is not installed');

it('decodes a Nano address to its public key', function (): void {
    expect(NanoAddress::publicKey(NANO_GENESIS))
        ->toBe('E89208DD038FBB269987689621D52292AE9C35941A7484756ECCED92A65093BA')
        ->and(NanoAddress::isValid('nano_1111111111111111111111111111111111111111111111111111hifc8npp'))->toBeTrue();
});

it('accepts the legacy xrb_ prefix as the same account', function (): void {
    $legacy = 'xrb_'.substr(NANO_GENESIS, 5);

    expect(NanoAddress::isValid($legacy))->toBeTrue()
        ->and(NanoAddress::matches($legacy, NANO_GENESIS))->toBeTrue()
        ->and(NanoAddress::matches(NANO_GENESIS, null))->toBeFalse()
        ->and(NanoAddress::matches('nano_1111111111111111111111111111111111111111111111111111hifc8npp', NANO_GENESIS))->toBeFalse();
});

it('rejects malformed addresses and bad checksums', function (string $address): void {
    expect(NanoAddress::isValid($address))->toBeFalse();
})->with([
    'bad checksum' => ['nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr4'],
    'wrong prefix' => ['ban_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3'],
    'too short' => ['nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr'],
    'outside the alphabet' => ['nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuoh2'],
    'evm address' => ['0x209693Bc6afc0C5328bA36FaF03C514EF312287C'],
]);

it('multiplies arbitrarily large integers', function (): void {
    expect(Integers::multiply('0', '123'))->toBe('0')
        ->and(Integers::multiply('99', '99'))->toBe('9801')
        ->and(Integers::multiply('1000000000000000000000000000000', '25'))->toBe('25000000000000000000000000000000');
});

it('converts a USD price at a rate for an asset that is not USD-pegged', function (): void {
    // $0.01 at 1.25 XNO per dollar is 0.0125 XNO.
    expect(UsdConverter::toAtomicAtRate('0.01', '1.25', 30))->toBe('12500000000000000000000000000')
        ->and(UsdConverter::toAtomicAtRate('2', '3', 0))->toBe('6')
        ->and(UsdConverter::toAtomicAtRate('0.001', '0.5', 3))->toBe('1');
});

it('takes pay_to and a rate from a nano:mainnet asset, without EIP-712 extras', function (): void {
    $asset = (new AssetRegistry(['nano' => [
        'address' => 'XNO', 'decimals' => 30, 'symbol' => 'XNO', 'name' => 'Nano',
        'pay_to' => NANO_GENESIS, 'units_per_usd' => '1.25',
    ]]))->for('nano:mainnet');

    expect($asset->payTo)->toBe(NANO_GENESIS)
        ->and($asset->unitsPerUsd)->toBe('1.25')
        ->and($asset->toAtomic('0.01'))->toBe('12500000000000000000000000000')
        ->and($asset->requirementsExtra())->toBe([]);
});

it('rejects a nano:mainnet asset without a valid pay_to or rate', function (array $definition, string $message): void {
    expect(fn (): Asset => Asset::fromArray('nano:mainnet', array_merge([
        'address' => 'XNO', 'decimals' => 30, 'symbol' => 'XNO', 'name' => 'Nano',
        'pay_to' => NANO_GENESIS, 'units_per_usd' => '1.25',
    ], $definition)))->toThrow(ConfigurationException::class, $message);
})->with([
    'missing pay_to' => [['pay_to' => null], 'missing "pay_to"'],
    'evm pay_to' => [['pay_to' => '0x209693Bc6afc0C5328bA36FaF03C514EF312287C'], 'not a valid Nano address'],
    'missing rate' => [['units_per_usd' => null], 'units_per_usd'],
    'zero rate' => [['units_per_usd' => '0'], 'units_per_usd'],
    'garbage rate' => [['units_per_usd' => 'lots'], 'units_per_usd'],
]);
