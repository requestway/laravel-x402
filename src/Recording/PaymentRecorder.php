<?php

declare(strict_types=1);

namespace Requestway\X402\Recording;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\VerifyResponse;
use Requestway\X402\Enums\PaymentStatus;
use Requestway\X402\Events\PaymentContext;
use Requestway\X402\Events\PaymentFailed;
use Requestway\X402\Events\PaymentObserved;
use Requestway\X402\Events\PaymentRequired;
use Requestway\X402\Events\PaymentSettled;
use Requestway\X402\Events\PaymentVerified;
use Requestway\X402\Models\X402Payment;
use Requestway\X402\Reporting\Reporter;
use Throwable;

/**
 * The single place where a payment outcome becomes an event, a log line, a
 * database row and an optional report.
 *
 * Bookkeeping must not be able to break a payment, so persistence and
 * reporting failures are reported to the exception handler and otherwise
 * ignored: a database that is down is not a reason to serve a paid resource
 * for free, nor to reject a payment that already settled.
 */
final class PaymentRecorder
{
    public function __construct(
        private readonly X402Config $config,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly ExceptionHandler $exceptions,
        private readonly Reporter $reporter,
    ) {}

    public function required(PaymentContext $context): void
    {
        $this->events->dispatch(new PaymentRequired($context));

        $this->logger->debug('x402 payment required.', $context->toArray());
    }

    /**
     * Record a verified payment. In after_response mode the row is written now
     * and updated when settlement completes, so a process that dies between
     * the two still leaves evidence that a resource was served.
     *
     * @return int|null the row id to pass to settled() or failed(), if any
     */
    public function verified(PaymentContext $context, VerifyResponse $verification, bool $persist = false): ?int
    {
        $this->events->dispatch(new PaymentVerified($context));

        $this->logger->debug('x402 payment verified.', $context->toArray() + [
            'payer' => $context->payerForLog($this->config->logsPayer()),
        ]);

        if (! $persist) {
            return null;
        }

        return $this->persist($context, PaymentStatus::Verified, [
            'payer' => $verification->payer ?? $context->payer(),
        ]);
    }

    public function settled(PaymentContext $context, SettleResponse $settlement, ?int $recordId = null): void
    {
        $this->events->dispatch(new PaymentSettled($context, $settlement));

        $this->logger->info('x402 payment settled.', $context->toArray() + [
            'payer' => $context->payerForLog($this->config->logsPayer()),
            'transaction' => $settlement->transaction,
        ]);

        $this->persist($context, $settlement->isPending() ? PaymentStatus::Pending : PaymentStatus::Settled, [
            'transaction_hash' => $settlement->transaction === '' ? null : $settlement->transaction,
            'payer' => $settlement->payer ?? $context->payer(),
            'amount_atomic' => $settlement->amount ?? $context->amountAtomic(),
            'failure_reason' => $settlement->isPending() ? $settlement->errorReason : null,
        ], $recordId);

        $this->report('payment.settled', $context, [
            'transaction' => $settlement->transaction,
            'pending' => $settlement->isPending(),
        ]);
    }

    /**
     * Stage for a payload that could not even be decoded. Not a payment
     * attempt, so it is not persisted by default: see failed().
     */
    public const STAGE_DECODE = 'decode';

    public function failed(
        PaymentContext $context,
        string $stage,
        string $reason,
        string $message,
        ?VerifyResponse $verification = null,
        ?SettleResponse $settlement = null,
        ?Throwable $exception = null,
        ?int $recordId = null,
    ): void {
        $this->events->dispatch(new PaymentFailed(
            $context,
            $stage,
            $reason,
            $message,
            $verification,
            $settlement,
            $exception,
        ));

        $payload = $context->toArray() + [
            'stage' => $stage,
            'reason' => $reason,
            'payer' => $context->payerForLog($this->config->logsPayer()),
        ];

        // A resource already served without payment is an operator problem,
        // not a client one, so it is logged louder.
        if ($stage === 'settle_after_response') {
            $this->logger->error('x402 settlement failed after the response was served.', $payload);
        } else {
            $this->logger->warning('x402 payment failed.', $payload);
        }

        // Undecodable input is not a payment attempt — anyone can send a
        // malformed header — so it is logged but not written to the database,
        // where it would let unauthenticated noise grow the table unbounded.
        if ($stage === self::STAGE_DECODE && ! $this->config->recordsMalformed()) {
            $this->report('payment.failed', $context, ['stage' => $stage, 'reason' => $reason]);

            return;
        }

        $this->persist($context, PaymentStatus::Failed, [
            'failure_reason' => substr($reason, 0, 120),
            'transaction_hash' => $settlement === null || $settlement->transaction === ''
                ? null
                : $settlement->transaction,
            'payer' => $this->firstAddress(
                $settlement === null ? null : $settlement->payer,
                $verification === null ? null : $verification->payer,
                $context->payer(),
            ),
            'meta' => ['stage' => $stage, 'message' => $message],
        ], $recordId);

        $this->report('payment.failed', $context, ['stage' => $stage, 'reason' => $reason]);
    }

    public function observed(PaymentContext $context): void
    {
        $this->events->dispatch(new PaymentObserved($context));

        $this->logger->info('x402 would have charged for this request.', $context->toArray());

        if ($this->config->recordsObserved()) {
            $this->persist($context, PaymentStatus::Observed);
        }

        $this->report('payment.observed', $context);
    }

    private function firstAddress(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function persist(PaymentContext $context, PaymentStatus $status, array $attributes = [], ?int $recordId = null): ?int
    {
        if (! $this->config->databaseEnabled()) {
            return null;
        }

        try {
            $meta = $attributes['meta'] ?? null;
            unset($attributes['meta']);

            $attributes = array_merge([
                'route' => $context->route->label(),
                'method' => $context->method,
                'resource' => $context->resourceUrl,
                'amount_atomic' => $context->amountAtomic(),
                'amount_usd' => $context->amountUsd(),
                'asset' => $context->asset?->address,
                'asset_decimals' => $context->asset?->decimals,
                'network' => $context->network(),
                'payer' => $context->payer(),
                'pay_to' => $context->requirements->payTo ?? $context->route->payTo,
                'status' => $status,
                'facilitator' => $this->facilitatorLabel(),
                'mode' => $context->mode,
                'request_id' => $context->requestId,
                'meta' => is_array($meta) ? $meta : null,
            ], $attributes);

            if ($recordId !== null) {
                $updated = X402Payment::query()->whereKey($recordId)->update($attributes);

                if ($updated > 0) {
                    return $recordId;
                }
            }

            /** @var X402Payment $payment */
            $payment = X402Payment::query()->create($attributes);

            return (int) $payment->getKey();
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function report(string $type, PaymentContext $context, array $extra = []): void
    {
        if (! $this->config->reportingEnabled()) {
            return;
        }

        try {
            $this->reporter->record([
                'type' => $type,
                'at' => gmdate('c'),
                'payment' => $context->toArray() + $extra,
            ]);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    private function facilitatorLabel(): ?string
    {
        $url = $this->config->get('facilitator.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }
}
