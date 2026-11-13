<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Support;

/**
 * Binds a Nano send block to one order (xno-binding v1, representative
 * commitment).
 *
 * A Nano block has no memo, so a confirmed send of the right amount to
 * `payTo` proves nothing about which request it paid for, and block hashes
 * are public. The payer therefore sets the send block's representative to
 * SHA-256("xno-binding/v1/rep|" . orderId . "|" . payTo . "|" . amount),
 * encoded as an account. The block's own signature covers that field, and
 * only the holder of `orderId` can show which order a block commits to.
 *
 * @see https://github.com/dhyabi2/xno-call-binding/blob/main/docs/BINDING-STANDARD.md
 */
final class NanoBinding
{
    public const SCHEME = 'xno-binding/v1/rep';

    /**
     * 22 base64url characters carry 132 bits, the floor for an id a payer
     * draws at random and keeps secret until it presents the payment.
     */
    private const ORDER_ID = '/^[A-Za-z0-9_-]{22,128}\z/';

    public static function isValidOrderId(string $orderId): bool
    {
        return preg_match(self::ORDER_ID, $orderId) === 1;
    }

    /**
     * The 32-byte commitment, raw binary.
     */
    public static function commitment(string $orderId, string $payTo, string $amount): string
    {
        return hash('sha256', self::SCHEME.'|'.$orderId.'|'.$payTo.'|'.$amount, true);
    }

    /**
     * The representative account a bound send block carries.
     */
    public static function representative(string $orderId, string $payTo, string $amount): string
    {
        return NanoAddress::fromPublicKey(self::commitment($orderId, $payTo, $amount));
    }
}
