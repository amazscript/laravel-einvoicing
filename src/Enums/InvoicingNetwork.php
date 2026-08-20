<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * The networks an address can be registered on.
 *
 * Registering on one is what makes a company reachable: declared and reachable
 * are different states, and only this one routes an invoice.
 */
enum InvoicingNetwork: string
{
    /** The French domestic network. What the reform mandates. */
    case DomesticFr = 'DOMESTIC_FR';

    /** Peppol, for cross-border invoicing. */
    case PeppolInternational = 'PEPPOL_INTERNATIONAL';
}
