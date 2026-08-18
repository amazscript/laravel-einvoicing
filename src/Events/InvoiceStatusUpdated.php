<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\Status;

/**
 * Un statut de cycle de vie a été reçu et consigné.
 *
 * La facture concernée peut être inconnue du package : un statut arrive parfois
 * avant elle, ou porte sur un document qu'il n'a jamais vu. Le statut est alors
 * conservé sans rattachement.
 */
final class InvoiceStatusUpdated
{
    public function __construct(
        public readonly Status $status,
    ) {}
}
