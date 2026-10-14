<?php

declare(strict_types=1);

namespace Requestway\X402\Events;

use Requestway\X402\Core\Assets\Asset;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Core\Support\EvmAddress;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Routing\RoutePayment;

/**
 * Everything the package knows about one payment attempt.
 *
 * Shared by every x402 event, so a listener never has to reach back into the
 * request to find out what was being paid for.
 */
final class PaymentContext
{
    public function __construct(
        public readonly RoutePayment $route,
        public readonly string $resourceUrl,
        public readonly string $method,
        public readonly Mode $mode,
        public readonly ?PaymentRequirements $requirements = null,
        public readonly ?PaymentPayload $payload = null,
        public readonly ?Asset $asset = null,
        public readonly ?string $requestId = null,
    ) {}

    public function network(): ?string
    {
        return $this->requirements?->network;
    }

    public function amountAtomic(): ?string
    {
        return $this->requirements?->amount;
    }

    public function amountUsd(): string
    {
        return UsdConverter::parsePrice($this->route->price);
    }

    public function payer(): ?string
    {
        return $this->payload?->payerAddress();
    }

    /**
     * The payer address as it should appear in logs: abbreviated unless the
     * operator has opted into full addresses.
     */
    public function payerForLog(bool $full): string
    {
        $payer = $this->payer();

        return $full ? ($payer ?? '-') : EvmAddress::abbreviate($payer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route->label(),
            'method' => $this->method,
            'resource' => $this->resourceUrl,
            'mode' => $this->mode->value,
            'price_usd' => $this->amountUsd(),
            'amount_atomic' => $this->amountAtomic(),
            'asset' => $this->asset?->symbol,
            'network' => $this->network(),
            'pay_to' => $this->requirements->payTo ?? $this->route->payTo,
            'request_id' => $this->requestId,
        ];
    }
}
