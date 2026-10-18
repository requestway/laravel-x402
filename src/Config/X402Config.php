<?php

declare(strict_types=1);

namespace Requestway\X402\Config;

use Illuminate\Contracts\Config\Repository;
use Requestway\X402\Core\Assets\AssetRegistry;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Support\EvmAddress;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Enums\SettlementMode;

/**
 * Typed, validated access to config/x402.php.
 *
 * Reading config through this class rather than the array means a typo in an
 * environment variable surfaces as a clear exception instead of a route that
 * silently charges zero or pays nobody.
 *
 * Values are read from the config repository on demand, not snapshotted, so a
 * runtime change to config('x402.*') takes effect immediately.
 */
final class X402Config
{
    private ?AssetRegistry $assets = null;

    private ?string $assetsFingerprint = null;

    public function __construct(
        private readonly Repository $repository,
        private readonly string $prefix = 'x402',
    ) {}

    public function mode(): Mode
    {
        return Mode::parse($this->get('mode', 'enforce'));
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== Mode::Off;
    }

    public function settlementMode(): SettlementMode
    {
        return SettlementMode::parse($this->get('settlement.mode', 'before_response'));
    }

    public function failsOpenOnFacilitatorError(): bool
    {
        return $this->get('settlement.on_facilitator_error', 'fail_closed') === 'fail_open';
    }

    public function maxTimeoutSeconds(): int
    {
        $value = (int) $this->get('settlement.max_timeout_seconds', 60);

        return $value > 0 ? $value : 60;
    }

    /**
     * @throws ConfigurationException when no wallet is configured
     */
    public function payTo(): string
    {
        $payTo = $this->get('pay_to');

        if (! is_string($payTo) || trim($payTo) === '') {
            throw new ConfigurationException(
                'No x402 wallet configured. Set X402_PAY_TO to the address payments should go to, '
                .'or run: php artisan x402:install'
            );
        }

        return $this->assertAddress(trim($payTo), 'x402.pay_to');
    }

    public function hasPayTo(): bool
    {
        $payTo = $this->get('pay_to');

        return is_string($payTo) && trim($payTo) !== '';
    }

    public function assertAddress(string $address, string $context): string
    {
        if (! EvmAddress::isValid($address)) {
            throw new ConfigurationException(sprintf(
                '%s must be a 0x-prefixed 40-character EVM address, got "%s".',
                $context,
                $address
            ));
        }

        return $address;
    }

    public function network(): string
    {
        $network = $this->get('network', Network::BASE_SEPOLIA);

        return Network::normalize(is_string($network) && $network !== '' ? $network : Network::BASE_SEPOLIA);
    }

    /**
     * Every network advertised by default, the primary one first.
     *
     * @return list<string>
     */
    public function networks(): array
    {
        $networks = [$this->network()];

        foreach ($this->stringList('additional_networks') as $network) {
            $normalized = Network::normalize($network);

            if (! in_array($normalized, $networks, true)) {
                $networks[] = $normalized;
            }
        }

        return $networks;
    }

    public function assets(): AssetRegistry
    {
        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = is_array($this->get('assets')) ? $this->get('assets') : [];
        $fingerprint = md5(serialize($overrides));

        if ($this->assets === null || $this->assetsFingerprint !== $fingerprint) {
            $this->assets = new AssetRegistry($overrides);
            $this->assetsFingerprint = $fingerprint;
        }

        return $this->assets;
    }

    public function scheme(): string
    {
        $scheme = $this->get('defaults.scheme', 'exact');

        return is_string($scheme) && $scheme !== '' ? $scheme : 'exact';
    }

    /**
     * Resolve a price that may be a tier name, a decimal string or a number.
     *
     * @throws ConfigurationException
     */
    public function resolvePrice(string|int|float|null $price, string $context = 'route'): string
    {
        if ($price === null || $price === '') {
            $default = $this->get('defaults.price');

            if ($default === null || $default === '') {
                throw new ConfigurationException(sprintf(
                    'No price configured for %s. Pass one to the middleware (x402:0.01), set a '
                    .'#[RequiresPayment] attribute, or set X402_DEFAULT_PRICE.',
                    $context
                ));
            }

            /** @var string|int|float $default */
            $price = $default;
        }

        if (is_string($price)) {
            $tier = $this->tier($price);

            if ($tier !== null) {
                $price = $tier;
            }
        }

        return UsdConverter::parsePrice($price);
    }

    public function tier(string $name): string|int|float|null
    {
        $tiers = $this->get('tiers', []);

        if (! is_array($tiers)) {
            return null;
        }

        $value = $tiers[$name] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @return array<string, string|int|float>
     */
    public function tiers(): array
    {
        $tiers = $this->get('tiers', []);
        $resolved = [];

        foreach (is_array($tiers) ? $tiers : [] as $name => $price) {
            if (is_string($name) && (is_string($price) || is_int($price) || is_float($price))) {
                $resolved[$name] = $price;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function facilitator(): array
    {
        /** @var array<string, mixed> $facilitator */
        $facilitator = is_array($this->get('facilitator')) ? $this->get('facilitator') : [];

        return $facilitator;
    }

    public function facilitatorUrl(): string
    {
        $url = $this->get('facilitator.url', 'https://x402.org/facilitator');

        if (! is_string($url) || trim($url) === '') {
            throw new ConfigurationException('No facilitator URL configured. Set X402_FACILITATOR_URL.');
        }

        return rtrim(trim($url), '/');
    }

    /**
     * The Nano node that verifies `nano:mainnet` payments. Unset, Nano
     * payments go to the facilitator like any other network.
     */
    public function nanoRpcUrl(): ?string
    {
        $url = $this->get('nano.rpc_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function replayStore(): ?string
    {
        $store = $this->get('replay.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    public function replayTtl(): int
    {
        $ttl = (int) $this->get('replay.ttl', 86400);

        return $ttl > 0 ? $ttl : 86400;
    }

    public function replayPrefix(): string
    {
        $prefix = $this->get('replay.prefix', 'x402:replay:');

        return is_string($prefix) ? $prefix : 'x402:replay:';
    }

    public function databaseEnabled(): bool
    {
        return (bool) $this->get('database.enabled', true);
    }

    public function recordsObserved(): bool
    {
        return (bool) $this->get('database.record_observed', true);
    }

    public function recordsMalformed(): bool
    {
        return (bool) $this->get('database.record_malformed', false);
    }

    public function table(): string
    {
        $table = $this->get('database.table', 'x402_payments');

        return is_string($table) && $table !== '' ? $table : 'x402_payments';
    }

    public function databaseConnection(): ?string
    {
        $connection = $this->get('database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function discoveryEnabled(): bool
    {
        return (bool) $this->get('discovery.enabled', true);
    }

    public function discoveryPath(): string
    {
        $path = $this->get('discovery.path', '.well-known/x402');

        return trim(is_string($path) && $path !== '' ? $path : '.well-known/x402', '/');
    }

    /**
     * @return list<string>
     */
    public function discoveryMiddleware(): array
    {
        $middleware = $this->get('discovery.middleware', []);

        return array_values(array_filter(is_array($middleware) ? $middleware : [], 'is_string'));
    }

    public function discoveryCacheTtl(): int
    {
        return max(0, (int) $this->get('discovery.cache_ttl', 60));
    }

    public function serviceName(): ?string
    {
        $name = $this->get('discovery.service_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function iconUrl(): ?string
    {
        $url = $this->get('discovery.icon_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->stringList('discovery.tags');
    }

    public function reportingEnabled(): bool
    {
        return (bool) $this->get('reporting.enabled', false) && $this->reportingKey() !== null;
    }

    public function reportingKey(): ?string
    {
        $key = $this->get('reporting.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function logChannel(): ?string
    {
        $channel = $this->get('logging.channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    public function logsPayer(): bool
    {
        return (bool) $this->get('logging.log_payer', false);
    }

    public function defaultDescription(): ?string
    {
        $description = $this->get('defaults.description');

        return is_string($description) && $description !== '' ? $description : null;
    }

    public function defaultMimeType(): ?string
    {
        $mimeType = $this->get('defaults.mime_type', 'application/json');

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function bypass(): array
    {
        /** @var array<string, mixed> $bypass */
        $bypass = is_array($this->get('bypass')) ? $this->get('bypass') : [];

        return $bypass;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($this->prefix.'.'.$key, $default) ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $config = $this->repository->get($this->prefix, []);

        /** @var array<string, mixed> $config */
        return is_array($config) ? $config : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->get($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            is_array($value) ? $value : []
        ), static fn (string $item): bool => $item !== ''));
    }
}
