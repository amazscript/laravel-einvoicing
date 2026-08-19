<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Establishes that an inbound request comes from the platform and was not
 * altered on the way.
 *
 * Replaceable: a different platform signs differently (v0.4).
 */
interface SignatureVerifier
{
    /**
     * @param  array<string, string>  $headers  header names in lower case
     * @param  string  $checksumSource  what to hash: the whole raw body for JSON,
     *                                  the file field content alone for multipart
     */
    public function verify(
        array $headers,
        string $method,
        string $pathWithQuery,
        string $checksumSource,
    ): bool;
}
