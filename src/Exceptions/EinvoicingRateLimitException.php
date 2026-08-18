<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 429 : quota dépassé. La plateforme recommande un backoff exponentiel,
 * appliqué côté job et non côté client HTTP.
 */
final class EinvoicingRateLimitException extends EinvoicingException
{
    public function __construct(
        string $message,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 429);
    }

    /**
     * Délai conseillé avant nouvelle tentative, en secondes, si la plateforme
     * l'a communiqué.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
