<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Assets;

use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Support\EvmAddress;
use Requestway\X402\Core\Support\NanoAddress;
use Throwable;

/**
 * A USD-pegged token on one network.
 *
 * `name` and `version` are the token contract's EIP-712 domain values. The
 * `exact`/`eip3009` scheme requires them in PaymentRequirements.extra: a client
 * cannot build a valid `transferWithAuthorization` signature without them.
 *
 * An asset that is not USD-pegged — XNO on `nano:mainnet` — carries its own
 * `payTo` and a `unitsPerUsd` rate instead, since a route's price is in USD
 * and the network's addresses are not EVM addresses.
 */
final class Asset
{
    /**
     * @param array<string, mixed> $extra additional scheme `extra` keys, e.g. assetTransferMethod
     */
    public function __construct(
        public readonly string $network,
        public readonly string $address,
        public readonly int $decimals,
        public readonly string $symbol,
        public readonly string $name,
        public readonly string $version,
        public readonly array $extra = [],
        public readonly ?string $payTo = null,
        public readonly ?string $unitsPerUsd = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(string $network, array $definition): self
    {
        $address = self::requireString($definition, 'address', $network);

        if (Network::isEvm($network) && ! EvmAddress::isValid($address)) {
            throw new ConfigurationException(sprintf(
                'Asset address "%s" for network %s is not a valid EVM address.',
                $address,
                $network
            ));
        }

        $decimals = $definition['decimals'] ?? null;

        if (! is_int($decimals) && ! (is_string($decimals) && ctype_digit($decimals))) {
            throw new ConfigurationException(sprintf('Asset "%s" is missing integer "decimals".', $network));
        }

        $extra = $definition['extra'] ?? [];
        $payTo = null;
        $unitsPerUsd = null;

        if (Network::isNano($network)) {
            $payTo = self::requireString($definition, 'pay_to', $network);

            if (! NanoAddress::isValid($payTo)) {
                throw new ConfigurationException(sprintf(
                    'pay_to "%s" for network %s is not a valid Nano address.',
                    $payTo,
                    $network
                ));
            }

            $unitsPerUsd = self::requireRate($definition, $network);
        }

        return new self(
            network: $network,
            address: $address,
            decimals: (int) $decimals,
            symbol: self::requireString($definition, 'symbol', $network),
            name: self::requireString($definition, 'name', $network),
            version: (string) ($definition['version'] ?? '2'),
            extra: is_array($extra) ? $extra : [],
            payTo: $payTo,
            unitsPerUsd: $unitsPerUsd,
        );
    }

    /**
     * The `extra` object for PaymentRequirements. The EIP-712 domain values are
     * always present; configured extras are merged over them.
     *
     * @return array<string, mixed>
     */
    public function requirementsExtra(): array
    {
        if (Network::isNano($this->network)) {
            return $this->extra;
        }

        return array_merge([
            'name' => $this->name,
            'version' => $this->version,
        ], $this->extra);
    }

    /**
     * Atomic units for a USD price on this asset.
     */
    public function toAtomic(string $price): string
    {
        return $this->unitsPerUsd === null
            ? UsdConverter::toAtomic($price, $this->decimals)
            : UsdConverter::toAtomicAtRate($price, $this->unitsPerUsd, $this->decimals);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function requireRate(array $definition, string $network): string
    {
        $rate = $definition['units_per_usd'] ?? null;

        try {
            $parsed = is_string($rate) || is_int($rate) || is_float($rate) ? UsdConverter::parsePrice($rate) : '0';
        } catch (Throwable) {
            $parsed = '0';
        }

        if ($parsed === '0') {
            throw new ConfigurationException(sprintf(
                'Asset for network %s needs "units_per_usd", a positive number of %s per US dollar.',
                $network,
                is_string($definition['symbol'] ?? null) ? $definition['symbol'] : 'units'
            ));
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function requireString(array $definition, string $key, string $network): string
    {
        $value = $definition[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf('Asset for network %s is missing "%s".', $network, $key));
        }

        return trim($value);
    }
}
