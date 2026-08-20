<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * When VAT falls due on a transaction.
 *
 * The platform's own vocabulary (`iopCode`), which sits alongside the UNTDID
 * codes. Only this one is used here: the numeric codes say the same thing in a
 * form nobody reads twice.
 */
enum VatPointDate: string
{
    case Unknown = 'UNKNOWN';
    case InvoiceDate = 'INVOICE_DATE';
    case DeliveryDate = 'DELIVERY_DATE';

    /** The usual case for services. */
    case PaymentDate = 'PAYMENT_DATE';
}
