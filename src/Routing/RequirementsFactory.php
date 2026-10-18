<?php

declare(strict_types=1);

namespace Requestway\X402\Routing;

use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Networks\Network;

/**
 * Turns a resolved route into the PaymentRequired object advertised on a 402.
 *
 * One PaymentRequirements entry is emitted per accepted network, so a client
 * can pay on whichever chain it holds funds on.
 */
final class RequirementsFactory
{
    public function __construct(private readonly X402Config $config) {}

    public function make(RoutePayment $route, string $resourceUrl, ?string $error = null): PaymentRequired
    {
        return new PaymentRequired(
            resource: $this->resource($route, $resourceUrl),
            accepts: $this->accepts($route),
            error: $error,
        );
    }

    public function resource(RoutePayment $route, string $resourceUrl): ResourceInfo
    {
        return new ResourceInfo(
            url: $resourceUrl,
            description: $route->description,
            mimeType: $route->mimeType,
            serviceName: $route->serviceName,
            tags: $route->tags,
            iconUrl: $route->iconUrl,
        );
    }

    /**
     * @return list<PaymentRequirements>
     *
     * @throws ConfigurationException
     */
    public function accepts(RoutePayment $route): array
    {
        $assets = $this->config->assets();
        $accepts = [];

        foreach ($route->networks as $network) {
            if (! $assets->has($network)) {
                throw new ConfigurationException(sprintf(
                    'Route %s accepts payments on %s but no asset is configured for that network. '
                    .'Add one under x402.assets.',
                    $route->label(),
                    Network::label($network)
                ));
            }

            $asset = $assets->for($network);

            $accepts[] = new PaymentRequirements(
                scheme: $route->scheme,
                network: $network,
                amount: $asset->toAtomic($route->price),
                asset: $asset->address,
                payTo: $asset->payTo ?? $route->payTo,
                maxTimeoutSeconds: $route->maxTimeoutSeconds,
                extra: $asset->requirementsExtra(),
            );
        }

        if ($accepts === []) {
            throw new ConfigurationException(sprintf(
                'Route %s accepts no networks. Check x402.network.',
                $route->label()
            ));
        }

        return $accepts;
    }
}
