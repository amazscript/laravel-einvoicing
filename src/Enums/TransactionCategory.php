<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * What kind of B2C transaction is being reported.
 *
 * E-reporting covers what e-invoicing does not: sales to consumers, where no
 * invoice travels the network and the tax authority would otherwise see nothing.
 */
enum TransactionCategory: string
{
    /** Sale of physical goods delivered to the customer. */
    case Goods = 'TLB1';

    /** Service provision. Requires a VAT point date. */
    case Services = 'TPS1';

    /** Outside the scope of VAT — exempt operations. */
    case NonTaxable = 'TNT1';

    /** Goods and services combined in a single declaration. */
    case Mixed = 'TMA1';

    /**
     * Whether the platform requires a VAT point date for this category.
     *
     * On services, VAT falls due on payment rather than on delivery, so the
     * date has to be stated; the platform rejects the call without it.
     */
    public function needsVatPointDate(): bool
    {
        return $this === self::Services;
    }
}
