<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * The French VAT regime an entity files under.
 *
 * Optional when declaring an entity, but it drives e-reporting deadlines: a
 * monthly filer and a quarterly one do not owe the same thing at the same time.
 */
enum VatRegime: string
{
    case RealMonthly = 'REAL_MONTHLY_TAX_REGIME';
    case RealQuarterly = 'REAL_QUARTERLY_TAX_REGIME';
    case Simplified = 'SIMPLIFIED_TAX_REGIME';
    case Exempt = 'VAT_EXEMPTION_REGIME';
}
