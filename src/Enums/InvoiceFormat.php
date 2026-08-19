<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Source format of a received invoice.
 *
 * The package produces none of these: it records whichever one the accredited
 * platform reports.
 */
enum InvoiceFormat: string
{
    case Facturx = 'FACTURX';
    case Ubl = 'UBL';
    case Cii = 'CII';
}
