<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Maps bank-specific status vocabularies to a small set of abstract
 * results, then applies the right WooCommerce order transition.
 *
 * Vocabularies covered:
 *   - TBC card (TPay): Created, Pending, Processing, Succeeded,
 *     WaitingConfirm, Approved, Completed, Failed, Declined,
 *     Cancelled, Expired, Returned, PartialReturned.
 *   - TBC installment: Pending, Approved, Confirmed, Canceled,
 *     Rejected, Expired.
 *   - BOG card (Business API v1): order_status.key values.
 *   - BOG installment: status x event tuple.
 *   - Flitt: approved/declined/expired/processing/created/reversed.
 *
 * `apply()` prefers `WC_Order::payment_complete()` for success, which
 * transitions to `processing` or `completed` depending on whether
 * the order needs physical fulfilment — standard WC behaviour.
 *
 * Pure helper, zero state. Lifted from the legacy mega-plugin's
 * `Codeon\Payments\OrderStatusMapper`. Lazy-loaded behind the
 * `class_exists('WC_Order')` guard so the framework stays
 * WooCommerce-agnostic at the package level.
 */
if (!class_exists('WC_Order')) {
    return;
}

final class OrderStatusMapper
{
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_CANCEL  = 'cancel';
    public const RESULT_REFUND  = 'refund';
    public const RESULT_PENDING = 'pending';

    public static function fromTbcCardStatus(string $status): string
    {
        return match ($status) {
            'Succeeded', 'Completed', 'Approved'   => self::RESULT_SUCCESS,
            'Failed', 'Declined', 'Expired'        => self::RESULT_FAILURE,
            'Cancelled'                            => self::RESULT_CANCEL,
            'Returned', 'PartialReturned'          => self::RESULT_REFUND,
            default                                => self::RESULT_PENDING,
        };
    }

    public static function fromTbcInstallmentStatus(string $status): string
    {
        return match ($status) {
            'Approved', 'Confirmed'                 => self::RESULT_SUCCESS,
            // Rejected / Expired / Canceled all end the same way: no
            // loan was disbursed, no money moved. Map to WC "cancelled"
            // so stock auto-restocks immediately — far more useful
            // than the 60-min hold-stock timeout of "failed".
            'Rejected', 'Expired', 'Canceled'       => self::RESULT_CANCEL,
            default                                  => self::RESULT_PENDING,
        };
    }

    public static function fromBogIpayEvent(string $event, string $status): string
    {
        $s = strtolower($status);
        $e = strtolower($event);

        if ($s === 'completed' || $s === 'partial_completed') {
            return self::RESULT_SUCCESS;
        }
        // Pre-authorised (MANUAL capture) — held but not captured.
        // Pending; admin must capture or cancel.
        if ($s === 'blocked') {
            return self::RESULT_PENDING;
        }
        if ($s === 'rejected') {
            return self::RESULT_FAILURE;
        }
        if ($s === 'refunded' || $s === 'refunded_partially' || $e === 'refund') {
            return self::RESULT_REFUND;
        }
        return self::RESULT_PENDING;
    }

    public static function fromBogInstallmentEvent(string $event, string $status): string
    {
        $e = strtolower($event);
        $s = strtolower($status);

        if ($e === 'order_payment' && $s === 'success') {
            return self::RESULT_SUCCESS;
        }
        if ($e === 'order_cancelled') {
            return self::RESULT_CANCEL;
        }
        if ($e === 'order_reverse') {
            return self::RESULT_REFUND;
        }
        if (in_array($s, ['reject', 'error'], true)) {
            return self::RESULT_FAILURE;
        }
        return self::RESULT_PENDING;
    }

    public static function fromFlittEvent(string $status, string $tranType = ''): string
    {
        $s = strtolower($status);
        $t = strtolower($tranType);

        if ($t === 'reverse' || $s === 'reversed') {
            return self::RESULT_REFUND;
        }
        if ($s === 'approved') {
            return self::RESULT_SUCCESS;
        }
        if ($s === 'declined') {
            return self::RESULT_FAILURE;
        }
        // Expired Flitt holds release; map to cancel so stock
        // auto-restocks (consistent with TBC installment).
        if ($s === 'expired') {
            return self::RESULT_CANCEL;
        }
        return self::RESULT_PENDING;
    }

    public static function apply(\WC_Order $order, string $result, string $transactionId, string $note): void
    {
        switch ($result) {
            case self::RESULT_SUCCESS:
                $order->payment_complete($transactionId);
                $order->add_order_note($note);
                return;

            case self::RESULT_FAILURE:
                if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
                    return;
                }
                $order->update_status('failed', $note);
                return;

            case self::RESULT_CANCEL:
                if ($order->has_status(['cancelled', 'refunded'])) {
                    return;
                }
                $order->update_status('cancelled', $note);
                return;

            case self::RESULT_REFUND:
                // Don't auto-refund from a webhook; just annotate.
                $order->add_order_note($note);
                return;

            case self::RESULT_PENDING:
            default:
                $order->add_order_note($note);
                return;
        }
    }
}
