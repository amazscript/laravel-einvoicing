<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Format d'origine de la facture reçue.
 *
 * Le package ne produit aucun de ces formats : il constate celui que la
 * Plateforme Agréée annonce.
 */
enum InvoiceFormat: string
{
    case Facturx = 'FACTURX';
    case Ubl = 'UBL';
    case Cii = 'CII';
}
