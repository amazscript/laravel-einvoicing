<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 429: quota exceeded. The platform recommends exponential backoff, which is
 * applied by the queued job rather than by the HTTP client.
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
     * Seconds to wait before retrying, when the platform states it.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
