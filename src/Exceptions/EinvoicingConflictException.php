<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 409: conflict with the current state of the server.
 *
 * DUPLICATE_RESOURCE means the resource already exists. That is the expected
 * outcome of a replay, and the caller should treat it as a success — which
 * isDuplicateResource() allows. The client does not decide on its behalf.
 */
final class EinvoicingConflictException extends EinvoicingException
{
    public function __construct(
        string $message,
        // Named errorCode rather than code: Exception already owns a $code property.
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
