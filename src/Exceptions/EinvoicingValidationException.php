<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 400 : la requête a été refusée par la validation de schéma de la plateforme.
 *
 * Le corps renvoyé suit le format Zod : chaque erreur porte un chemin de champ,
 * un code et un message. On les expose tels quels plutôt que de les reformuler :
 * ce sont eux qui permettent de corriger l'appel.
 */
final class EinvoicingValidationException extends EinvoicingException
{
    /**
     * @param  list<array{path: string, code: string|null, message: string|null}>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message, 400);
    }

    /**
     * @return list<array{path: string, code: string|null, message: string|null}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
