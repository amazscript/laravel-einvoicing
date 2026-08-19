<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 400: the request was rejected by the platform's schema validation.
 *
 * The body follows a Zod shape: each error carries a field path, a code and a
 * message. They are exposed as-is rather than reworded — they are what lets a
 * developer fix the call.
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
