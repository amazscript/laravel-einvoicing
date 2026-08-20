<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * What kind of taxpayer an entity is, from the platform's point of view.
 *
 * Sent when declaring an entity, and validated against a closed set — hence an
 * enum, on the same reasoning as BuyerStatus.
 */
enum EntityScope: string
{
    /** An ordinary company. The usual case. */
    case PrivateTaxPayer = 'PRIVATE_TAX_PAYER';

    /** A public body — invoices go through Chorus Pro. */
    case Public = 'PUBLIC';

    case Primary = 'PRIMARY';
    case Secondary = 'SECONDARY';
}
