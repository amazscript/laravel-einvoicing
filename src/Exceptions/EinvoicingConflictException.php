<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 409 : conflit avec l'état du serveur.
 *
 * Le cas DUPLICATE_RESOURCE signale que la ressource existe déjà. C'est le
 * résultat attendu d'un rejeu : l'appelant doit le traiter comme un succès,
 * ce que permet isDuplicateResource(). Le client, lui, ne décide pas à sa place.
 */
final class EinvoicingConflictException extends EinvoicingException
{
    public function __construct(
        string $message,
        // Nommée errorCode et non code : Exception possède déjà une propriété $code.
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, 409);
    }

    public function code(): ?string
    {
        return $this->errorCode;
    }

    public function isDuplicateResource(): bool
    {
        return $this->errorCode === 'DUPLICATE_RESOURCE';
    }
}
