<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

use RuntimeException;

/**
 * Racine des exceptions du package.
 *
 * Aucun message d'exception ne doit contenir de jeton, de secret ni de
 * customer-id : ces messages remontent dans les logs et les rapports d'erreur.
 */
abstract class EinvoicingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
