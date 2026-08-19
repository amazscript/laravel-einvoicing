<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\InboundInvoice;

/**
 * Une facture fournisseur est arrivée et a été consignée.
 *
 * C'est l'événement que l'application hôte écoute pour intégrer la facture dans
 * sa comptabilité.
 */
final class InboundInvoiceReceived
{
    public function __construct(
        public readonly InboundInvoice $invoice,
    ) {}
}
