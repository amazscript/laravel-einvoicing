<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * What a buyer tells the network about an invoice it received.
 *
 * Unlike the lifecycle codes the platform reports — an open list the package
 * deliberately does not model — these are values the package *sends*. The API
 * validates them against a closed set, so an enum protects the caller from a
 * 400 rather than hiding a genuine code.
 *
 * Under the French reform these statuses are how a buyer answers a supplier:
 * silence is not an answer, and some of them are mandatory.
 */
enum BuyerStatus: string
{
    /** Received and taken into the buyer's own system. */
    case InHand = 'IN_HAND';

    case Approved = 'APPROVED';
    case PartiallyApproved = 'PARTIALLY_APPROVED';

    /** Contested — the supplier is expected to answer. */
    case Disputed = 'DISPUTED';

    case Suspended = 'SUSPENDED';

    /** Settled and closed. */
    case Completed = 'COMPLETED';

    /** Refused outright. Requires a reason. */
    case Refused = 'REFUSED';

    case PaymentSent = 'PAYMENT_SENT';
    case PaymentReceived = 'PAYMENT_RECEIVED';

    /**
     * Whether this status must carry a reason to be meaningful.
     */
    public function needsReason(): bool
    {
        return $this === self::Refused;
    }

    /**
     * Whether this status must carry an amount to be meaningful.
     */
    public function needsPayment(): bool
    {
        return $this === self::PaymentSent || $this === self::PaymentReceived;
    }
}
