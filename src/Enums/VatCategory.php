<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * VAT category of a taxed amount (UNTDID 5305).
 *
 * A standard code list, not a platform one.
 */
enum VatCategory: string
{
    /** Standard rate. The usual case. */
    case Standard = 'S';

    /** Exempt from VAT. */
    case Exempt = 'E';

    /** Reverse charge — the customer accounts for the VAT. */
    case ReverseCharge = 'AE';

    /** Intra-community supply. */
    case IntraCommunity = 'K';

    /** Export outside the EU. */
    case Export = 'G';

    /** Outside the scope of VAT. */
    case OutOfScope = 'O';

    /** Zero rated. */
    case ZeroRated = 'Z';
}
