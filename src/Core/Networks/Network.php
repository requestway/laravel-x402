<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Networks;

use Requestway\X402\Core\Exceptions\ConfigurationException;

/**
 * CAIP-2 network identifiers.
 *
 * x402 v2 carries networks as CAIP-2 `namespace:reference` strings. Protocol v1
 * used friendly names, which this class accepts as input and normalizes, so
 * existing config keeps working.
 */
final class Network
{
    /** @var array<string, string> */
    public const ALIASES = [
        'base' => 'eip155:8453',
        'base-mainnet' => 'eip155:8453',
        'base-sepolia' => 'eip155:84532',
        'ethereum' => 'eip155:1',
        'mainnet' => 'eip155:1',
        'avalanche' => 'eip155:43114',
        'avalanche-fuji' => 'eip155:43113',
        'polygon' => 'eip155:137',
        'arbitrum' => 'eip155:42161',
        'arbitrum-one' => 'eip155:42161',
        'arbitrum-sepolia' => 'eip155:421614',
        'celo' => 'eip155:42220',
        'sei' => 'eip155:1329',
        'nano' => self::NANO,
    ];

    /** @var array<string, string> */
    public const NAMES = [
        'eip155:1' => 'Ethereum',
        'eip155:14' => 'Flare',
        'eip155:50' => 'XDC Network',
        'eip155:51' => 'XDC Apothem Testnet',
        'eip155:137' => 'Polygon',
        'eip155:143' => 'Monad',
        'eip155:1328' => 'Sei Testnet',
        'eip155:1329' => 'Sei',
        'eip155:5042' => 'Arc',
        'eip155:5042002' => 'Arc Testnet',
        'eip155:8453' => 'Base',
        'eip155:10143' => 'Monad Testnet',
        'eip155:42161' => 'Arbitrum One',
        'eip155:42220' => 'Celo',
        'eip155:43113' => 'Avalanche Fuji',
        'eip155:43114' => 'Avalanche',
        'eip155:84532' => 'Base Sepolia',
        'eip155:421614' => 'Arbitrum Sepolia',
        'eip155:11142220' => 'Celo Sepolia',
        self::NANO => 'Nano',
    ];

    /**
     * Networks that move real money. Used by x402:doctor and x402:install to
     * warn before an operator goes live.
     *
     * @var list<string>
     */
    public const TESTNETS = [
        'eip155:51',
        'eip155:1328',
        'eip155:5042002',
        'eip155:10143',
        'eip155:43113',
        'eip155:84532',
        'eip155:421614',
        'eip155:11142220',
    ];

    public const BASE_SEPOLIA = 'eip155:84532';

    public const BASE = 'eip155:8453';

    public const NANO = 'nano:mainnet';

    /**
     * Normalize a configured network to CAIP-2.
     *
     * @throws ConfigurationException
     */
    public static function normalize(string $network): string
    {
        $value = trim($network);

        if ($value === '') {
            throw new ConfigurationException('Network must not be empty.');
        }

        $alias = self::ALIASES[strtolower($value)] ?? null;

        if ($alias !== null) {
            return $alias;
        }

        if (! self::isCaip2($value)) {
            throw new ConfigurationException(sprintf(
                'Network "%s" is neither a CAIP-2 identifier (e.g. "eip155:8453") nor a known alias.',
                $network
            ));
        }

        return $value;
    }

    public static function isCaip2(string $network): bool
    {
        return preg_match('/^[-a-z0-9]{3,8}:[-_a-zA-Z0-9]{1,32}$/', $network) === 1;
    }

    public static function isTestnet(string $network): bool
    {
        return in_array(self::normalize($network), self::TESTNETS, true);
    }

    /**
     * A display name, falling back to the identifier itself.
     */
    public static function label(string $network): string
    {
        return self::NAMES[$network] ?? $network;
    }

    public static function namespace(string $network): string
    {
        $parts = explode(':', $network, 2);

        return $parts[0];
    }

    public static function isEvm(string $network): bool
    {
        return self::namespace($network) === 'eip155';
    }

    public static function isNano(string $network): bool
    {
        return $network === self::NANO;
    }
}
