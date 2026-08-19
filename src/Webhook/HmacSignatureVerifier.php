<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Contracts\SignatureVerifier;

/**
 * HMAC-SHA256 verification of the platform's inbound requests.
 *
 * Canonical string:
 *
 *     {X-Timestamp}\n{METHOD}\n{path_with_query}\n{checksum}
 *
 * The checksum covers the whole raw body for `application/json`, but only the
 * file field content for `multipart/form-data` — the other form fields are
 * excluded. This is the main source of error in the integration, and supplying
 * the right source is the caller's job: this class does not guess the content
 * type.
 *
 * Signature and checksum are hexadecimal.
 */
final class HmacSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function verify(
        array $headers,
        string $method,
        string $pathWithQuery,
        string $checksumSource,
    ): bool {
        // A missing secret must never amount to skipping verification.
        if ($this->secret === '') {
            return false;
        }

        $timestamp = $headers['x-timestamp'] ?? null;
        $signature = $headers['x-signature'] ?? null;

        if (! is_string($timestamp) || ! is_string($signature) || $timestamp === '' || $signature === '') {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $checksum = hash('sha256', $checksumSource);

        // The checksum header is optional, but when present it has to match:
        // it is checked before the signature, as the documentation requires.
        $received = $headers['x-checksum'] ?? null;

        if (is_string($received) && $received !== '' && ! hash_equals($checksum, $received)) {
            return false;
        }

        $canonical = $timestamp."\n".strtoupper($method)."\n".$pathWithQuery."\n".$checksum;
        $expected = hash_hmac('sha256', $canonical, $this->secret);

        // Constant-time comparison: a naive one would leak the expected
        // signature byte by byte, through response timing.
        return hash_equals($expected, $signature);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! is_numeric($timestamp)) {
            return false;
        }

        // The drift is taken in absolute value: a clock running ahead is as
        // suspect as a request replayed after the fact.
        return abs(time() - $this->toSeconds((int) $timestamp)) <= $this->toleranceSeconds;
    }

    /**
     * Brings the timestamp back to seconds.
     *
     * Observed on a real delivery: the platform sends milliseconds, which no
     * page of its documentation mentions. Compared to time() as-is, the drift is
     * measured in thousands of years and every genuine delivery is rejected. The
     * unit is not contractual, so both are accepted.
     *
     * The threshold reads as year 5138 in seconds and November 1973 in
     * milliseconds: no plausible value can be misclassified.
     */
    private function toSeconds(int $timestamp): int
    {
        return $timestamp > 100_000_000_000 ? intdiv($timestamp, 1000) : $timestamp;
    }
}
