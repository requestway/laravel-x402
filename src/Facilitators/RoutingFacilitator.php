<?php

declare(strict_types=1);

namespace Requestway\X402\Facilitators;

use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;

/**
 * Sends each payment to the facilitator registered for its network, and
 * everything else to the default one.
 */
final class RoutingFacilitator implements Facilitator
{
    /**
     * @param array<string, Facilitator> $networks keyed by CAIP-2 identifier
     */
    public function __construct(
        private readonly Facilitator $default,
        private readonly array $networks,
    ) {}

    public function verify(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): VerifyResponse
    {
        return $this->for($requirements)->verify($payload, $requirements, $resource);
    }

    public function settle(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse
    {
        return $this->for($requirements)->settle($payload, $requirements, $resource);
    }

    public function supported(): SupportedResponse
    {
        $supported = $this->default->supported();
        $kinds = $supported->kinds;

        foreach ($this->networks as $facilitator) {
            $kinds = array_merge($kinds, $facilitator->supported()->kinds);
        }

        return new SupportedResponse($kinds, $supported->extensions, $supported->signers);
    }

    public function for(PaymentRequirements $requirements): Facilitator
    {
        return $this->networks[$requirements->network] ?? $this->default;
    }
}
