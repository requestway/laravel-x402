<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Facilitator;

use Closure;
use Requestway\X402\Core\Errors\ErrorCode;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorConnectionException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorResponseException;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedKind;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;
use Requestway\X402\Core\Money\Integers;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Protocol\Version;
use Requestway\X402\Core\Support\Clock;
use Requestway\X402\Core\Support\NanoAddress;
use Requestway\X402\Core\Support\NanoBinding;
use Requestway\X402\Core\Support\SystemClock;
use Throwable;

/**
 * Verifies `nano:mainnet` payments against a Nano node the operator runs or
 * trusts, with no third-party facilitator.
 *
 * The client pays first, by publishing a send block to `payTo`, and presents
 * its hash as `{"blockHash": "…"}`. A Nano send is final once the network
 * confirms it, so there is nothing to broadcast: settling re-reads the block,
 * and this class holds no key and never moves funds.
 *
 * A confirmed block stays valid forever, so delivering twice is prevented by
 * the resource server's ReplayGuard, and a block older than the guard's
 * retention window is refused here so the guard always still remembers it.
 *
 * With `$requireBinding`, a block also has to commit to the order it pays
 * for (NanoBinding): the payload carries the payer's secret `orderId`, and the
 * block's representative must be the commitment to that id, `payTo` and the
 * exact amount, so a public block hash cannot pay for anyone else's request.
 */
final class NanoFacilitator implements Facilitator
{
    /**
     * @param Closure(array<string, string>): array<string, mixed> $rpc posts one action to the node's JSON RPC
     */
    public function __construct(
        private readonly Closure $rpc,
        private readonly int $maxAgeSeconds,
        private readonly Clock $clock = new SystemClock,
        private readonly bool $requireBinding = false,
    ) {}

    public function verify(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): VerifyResponse
    {
        [$failure, $payer] = $this->check($payload, $requirements, 'verify');

        if ($failure !== null) {
            return VerifyResponse::invalid($failure[0], $payer, $failure[1]);
        }

        return VerifyResponse::valid($payer);
    }

    public function settle(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse
    {
        [$failure, $payer, $amount, $hash] = $this->check($payload, $requirements, 'settle');

        if ($failure !== null) {
            return SettleResponse::failed($requirements->network, $failure[0], $payer, message: $failure[1]);
        }

        return SettleResponse::settled($requirements->network, $hash, $payer, $amount);
    }

    public function supported(): SupportedResponse
    {
        return new SupportedResponse([
            new SupportedKind(Version::V2, PaymentRequirements::SCHEME_EXACT, Network::NANO),
        ]);
    }

    /**
     * @return array{0: array{0: ErrorCode, 1: string}|null, 1: string|null, 2: string|null, 3: string} failure, payer, amount, block hash
     *
     * @throws FacilitatorException
     */
    private function check(PaymentPayload $payload, PaymentRequirements $requirements, string $operation): array
    {
        if (! Network::isNano($requirements->network)) {
            return [[ErrorCode::InvalidNetwork, sprintf('%s is not a Nano network.', $requirements->network)], null, null, ''];
        }

        $hash = $payload->payload['blockHash'] ?? null;
        $orderId = $payload->payload['orderId'] ?? null;
        $keys = array_keys($payload->payload);
        sort($keys);

        // One spelling per block: ReplayGuard keys on the payload as sent, so
        // a lowercase hash or an extra key must not buy a second delivery. A
        // bound block commits to one orderId, so binding adds no spelling.
        if ($this->requireBinding) {
            if ($keys !== ['blockHash', 'orderId'] || ! is_string($hash) || preg_match('/^[0-9A-F]{64}\z/', $hash) !== 1
                || ! is_string($orderId) || ! NanoBinding::isValidOrderId($orderId)) {
                return [[ErrorCode::InvalidPayload, 'The payment must be exactly {"blockHash": "<64 uppercase hex>", "orderId": "<22 to 128 of A-Z a-z 0-9 _ ->"} for a send block bound to that order.'], null, null, ''];
            }
        } elseif ($keys !== ['blockHash'] || ! is_string($hash) || preg_match('/^[0-9A-F]{64}\z/', $hash) !== 1) {
            return [[ErrorCode::InvalidPayload, 'The payment must be exactly {"blockHash": "<64 uppercase hex>"} for a send block.'], null, null, ''];
        }

        $info = $this->blockInfo($hash, $operation);

        if ($info === null) {
            return [[ErrorCode::InvalidPayload, 'The Nano node does not know this block.'], null, null, $hash];
        }

        $contents = is_array($info['contents'] ?? null) ? $info['contents'] : [];
        $account = is_string($info['block_account'] ?? null) ? $info['block_account'] : null;
        $amount = is_string($info['amount'] ?? null) && Integers::isValid($info['amount']) ? Integers::normalize($info['amount']) : null;

        $failure = match (true) {
            ! in_array($info['confirmed'] ?? null, ['true', true], true) => [ErrorCode::InvalidTransactionState, 'The send block is not confirmed yet.'],
            ($info['subtype'] ?? null) !== 'send' || $account === null || $amount === null => [ErrorCode::InvalidPayload, 'The block is not a send block.'],
            ! NanoAddress::matches(is_string($contents['link_as_account'] ?? null) ? $contents['link_as_account'] : null, $requirements->payTo) => [ErrorCode::RecipientMismatch, ErrorCode::RecipientMismatch->message()],
            ! Integers::greaterThanOrEqual($amount, $requirements->amount) => [ErrorCode::ValueMismatch, 'The send block pays less than the required amount.'],
            $this->requireBinding && $amount !== Integers::normalize($requirements->amount) => [ErrorCode::ValueMismatch, 'A bound send block must pay exactly the required amount.'],
            $this->requireBinding && (! is_string($orderId) || ! $this->isBound($contents, $orderId, $requirements)) => [ErrorCode::InvalidPayload, 'The send block is not bound to this order: its representative is not the commitment to orderId, payTo and amount.'],
            ! $this->isRecent($info['local_timestamp'] ?? null) => [ErrorCode::InvalidPayload, 'The send block is older than the replay window.'],
            default => null,
        };

        return [$failure, $account, $amount, $hash];
    }

    /**
     * @param array<mixed> $contents
     */
    private function isBound(array $contents, string $orderId, PaymentRequirements $requirements): bool
    {
        $representative = $contents['representative'] ?? null;

        return NanoAddress::matches(
            is_string($representative) ? $representative : null,
            NanoBinding::representative($orderId, $requirements->payTo, Integers::normalize($requirements->amount)),
        );
    }

    private function isRecent(mixed $timestamp): bool
    {
        if (! is_string($timestamp) || ! ctype_digit($timestamp) || (int) $timestamp === 0) {
            return false;
        }

        return $this->clock->now() - (int) $timestamp <= $this->maxAgeSeconds;
    }

    /**
     * @return array<string, mixed>|null null when the node has never seen the block
     *
     * @throws FacilitatorException
     */
    private function blockInfo(string $hash, string $operation): ?array
    {
        try {
            $response = ($this->rpc)(['action' => 'block_info', 'json_block' => 'true', 'hash' => $hash]);
        } catch (FacilitatorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FacilitatorConnectionException(
                sprintf('Could not reach the Nano node: %s', $exception->getMessage()),
                operation: $operation,
                previous: $exception,
            );
        }

        $error = $response['error'] ?? null;

        if ($error === 'Block not found') {
            return null;
        }

        if ($error !== null) {
            throw new FacilitatorResponseException(
                sprintf('Nano node block_info failed: %s', is_string($error) ? $error : 'unknown error'),
                operation: $operation,
            );
        }

        return $response;
    }
}
