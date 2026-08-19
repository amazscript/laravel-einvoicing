<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

use RuntimeException;

/**
 * Root of the package's exceptions.
 *
 * No exception message may carry a token, a secret or a customer id: these
 * messages end up in logs and error reports.
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
