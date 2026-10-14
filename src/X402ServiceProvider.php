<?php

declare(strict_types=1);

namespace Requestway\X402;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Psr\Log\LoggerInterface;
use Requestway\X402\Bypass\BypassEvaluator;
use Requestway\X402\Commands\DoctorCommand;
use Requestway\X402\Commands\InstallCommand;
use Requestway\X402\Commands\RoutesCommand;
use Requestway\X402\Commands\TestCommand;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorResponseException;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Facilitator\FacilitatorAuth;
use Requestway\X402\Core\Facilitator\NanoFacilitator;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Protocol\CodecRegistry;
use Requestway\X402\Core\Replay\ReplayGuard;
use Requestway\X402\Core\Replay\ReplayStore;
use Requestway\X402\Core\Support\Clock;
use Requestway\X402\Core\Support\SystemClock;
use Requestway\X402\Core\Validation\PaymentValidator;
use Requestway\X402\Discovery\RouteCatalog;
use Requestway\X402\Facilitators\HttpFacilitator;
use Requestway\X402\Facilitators\RoutingFacilitator;
use Requestway\X402\Http\Controllers\DiscoveryController;
use Requestway\X402\Http\Middleware\HonorPaymentAttributes;
use Requestway\X402\Http\Middleware\RequirePayment;
use Requestway\X402\Recording\PaymentRecorder;
use Requestway\X402\Replay\CacheReplayStore;
use Requestway\X402\Reporting\HttpReporter;
use Requestway\X402\Reporting\NullReporter;
use Requestway\X402\Reporting\Reporter;
use Requestway\X402\Routing\RequirementsFactory;
use Requestway\X402\Routing\RouteResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class X402ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-x402')
            ->hasConfigFile('x402')
            ->hasMigration('create_x402_payments_table')
            ->hasCommands([
                InstallCommand::class,
                RoutesCommand::class,
                TestCommand::class,
                DoctorCommand::class,
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(X402Config::class, fn (): X402Config => new X402Config(
            $this->app->make(ConfigRepository::class)
        ));

        $this->app->singleton(CodecRegistry::class, static fn (): CodecRegistry => new CodecRegistry);

        $this->app->bind(Clock::class, static fn (): Clock => new SystemClock);

        $this->app->singleton(PaymentValidator::class, fn (): PaymentValidator => new PaymentValidator(
            $this->app->make(Clock::class)
        ));

        $this->app->singleton(RouteResolver::class, fn (): RouteResolver => new RouteResolver(
            $this->app->make(X402Config::class)
        ));

        $this->app->singleton(RequirementsFactory::class, fn (): RequirementsFactory => new RequirementsFactory(
            $this->app->make(X402Config::class)
        ));

        $this->app->singleton(BypassEvaluator::class, fn (): BypassEvaluator => new BypassEvaluator(
            $this->app->make(X402Config::class)
        ));

        $this->app->singleton(ReplayStore::class, function (): ReplayStore {
            $config = $this->app->make(X402Config::class);

            return new CacheReplayStore(
                $this->app->make(CacheFactory::class)->store($config->replayStore())
            );
        });

        $this->app->singleton(ReplayGuard::class, function (): ReplayGuard {
            $config = $this->app->make(X402Config::class);

            return new ReplayGuard(
                $this->app->make(ReplayStore::class),
                $config->replayTtl(),
                $config->replayPrefix(),
            );
        });

        $this->app->singleton(Facilitator::class, function (): Facilitator {
            $config = $this->app->make(X402Config::class);
            $facilitator = $config->facilitator();

            /** @var array<string, string> $headers */
            $headers = is_array($facilitator['headers'] ?? null) ? $facilitator['headers'] : [];

            $auth = $facilitator['auth'] ?? null;

            $http = new HttpFacilitator(
                http: $this->app->make(HttpFactory::class),
                codecs: $this->app->make(CodecRegistry::class),
                baseUrl: $config->facilitatorUrl(),
                headers: $headers,
                timeout: (int) ($facilitator['timeout'] ?? 30),
                connectTimeout: (int) ($facilitator['connect_timeout'] ?? 5),
                retries: (int) ($facilitator['retries'] ?? 2),
                settleRetries: (int) ($facilitator['settle_retries'] ?? 0),
                retryDelay: (int) ($facilitator['retry_delay'] ?? 250),
                verifySsl: (bool) ($facilitator['verify_ssl'] ?? true),
                auth: match (true) {
                    $auth instanceof FacilitatorAuth => $auth,
                    is_string($auth) && $auth !== '' => $this->app->make($auth),
                    default => null,
                },
            );

            $nodeUrl = $config->nanoRpcUrl();

            if ($nodeUrl === null) {
                return $http;
            }

            return new RoutingFacilitator($http, [
                Network::NANO => new NanoFacilitator(
                    rpc: $this->nanoRpc($nodeUrl, (int) $config->get('nano.timeout', 10)),
                    maxAgeSeconds: $config->replayTtl(),
                    clock: $this->app->make(Clock::class),
                ),
            ]);
        });

        $this->app->singleton(Reporter::class, function (): Reporter {
            $config = $this->app->make(X402Config::class);
            $key = $config->reportingKey();

            if (! $config->reportingEnabled() || $key === null) {
                return new NullReporter;
            }

            $url = $config->get('reporting.url');
            $queue = $config->get('reporting.queue');
            $connection = $config->get('reporting.connection');

            return new HttpReporter(
                bus: $this->app->make(BusDispatcher::class),
                exceptions: $this->app->make(ExceptionHandler::class),
                url: is_string($url) ? $url : '',
                key: $key,
                queue: is_string($queue) && $queue !== '' ? $queue : null,
                connection: is_string($connection) && $connection !== '' ? $connection : null,
                timeout: (int) $config->get('reporting.timeout', 5),
            );
        });

        // Bound rather than shared: the recorder holds the event dispatcher
        // and the logger, and a test that swaps either (Event::fake(), a log
        // channel) must be seen by the next payment, not the first.
        $this->app->bind(PaymentRecorder::class, fn (): PaymentRecorder => new PaymentRecorder(
            config: $this->app->make(X402Config::class),
            events: $this->app->make(EventDispatcher::class),
            logger: $this->logger(),
            exceptions: $this->app->make(ExceptionHandler::class),
            reporter: $this->app->make(Reporter::class),
        ));

        $this->app->singleton(RouteCatalog::class, fn (): RouteCatalog => new RouteCatalog(
            router: $this->app->make(Router::class),
            resolver: $this->app->make(RouteResolver::class),
            requirements: $this->app->make(RequirementsFactory::class),
            config: $this->app->make(X402Config::class),
            url: $this->app->make(UrlGenerator::class),
            bypass: $this->app->make(BypassEvaluator::class),
        ));

        $this->app->singleton(X402Manager::class, fn (): X402Manager => new X402Manager(
            container: $this->app,
            config: $this->app->make(X402Config::class),
            codecs: $this->app->make(CodecRegistry::class),
            routes: $this->app->make(RouteResolver::class),
            requirements: $this->app->make(RequirementsFactory::class),
            validator: $this->app->make(PaymentValidator::class),
            bypass: $this->app->make(BypassEvaluator::class),
        ));

        $this->app->alias(X402Manager::class, 'x402');
    }

    public function bootingPackage(): void
    {
        $this->registerMiddleware();
        $this->registerRouteMacros();
        $this->registerDiscoveryRoute();
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('x402', RequirePayment::class);
        $router->aliasMiddleware('x402.attributes', HonorPaymentAttributes::class);
    }

    /**
     * Adds the fluent route helper:
     *
     *     Route::get('/report', ReportController::class)->paid('0.01');
     */
    private function registerRouteMacros(): void
    {
        if (Route::hasMacro('paid')) {
            return;
        }

        Route::macro('paid', function (string|int|float|null $price = null, array $options = []): Route {
            /** @var Route $this */
            $options = array_filter(
                array_merge($options, ['price' => $price ?? ($options['price'] ?? null)]),
                static fn (mixed $value): bool => $value !== null
            );

            $action = $this->getAction();
            $existing = is_array($action['x402'] ?? null) ? $action['x402'] : [];

            $this->setAction(array_merge($action, ['x402' => array_merge($existing, $options)]));

            return $this->middleware('x402');
        });
    }

    private function registerDiscoveryRoute(): void
    {
        $config = $this->app->make(X402Config::class);

        if (! $config->discoveryEnabled()) {
            return;
        }

        RouteFacade::middleware($config->discoveryMiddleware())
            ->get($config->discoveryPath(), DiscoveryController::class)
            ->name('x402.discovery');
    }

    /**
     * @return Closure(array<string, string>): array<string, mixed>
     */
    private function nanoRpc(string $url, int $timeout): Closure
    {
        return function (array $body) use ($url, $timeout): array {
            $response = $this->app->make(HttpFactory::class)
                ->asJson()
                ->acceptJson()
                ->timeout(max(1, $timeout))
                ->post($url, $body);

            /** @var mixed $decoded */
            $decoded = $response->json();

            if ($response->failed() || ! is_array($decoded)) {
                throw new FacilitatorResponseException(
                    sprintf('Nano node returned HTTP %d.', $response->status()),
                    operation: (string) ($body['action'] ?? ''),
                    status: $response->status(),
                );
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        };
    }

    private function logger(): LoggerInterface
    {
        $channel = $this->app->make(X402Config::class)->logChannel();
        $logger = $this->app->make(LoggerInterface::class);

        if ($channel === null || ! $logger instanceof LogManager) {
            return $logger;
        }

        return $logger->channel($channel);
    }
}
