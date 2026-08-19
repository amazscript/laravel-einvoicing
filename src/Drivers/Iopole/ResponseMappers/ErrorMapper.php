<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers;

use AmazScript\Einvoicing\Exceptions\EinvoicingAuthException;
use AmazScript\Einvoicing\Exceptions\EinvoicingConflictException;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use AmazScript\Einvoicing\Exceptions\EinvoicingRateLimitException;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use AmazScript\Einvoicing\Exceptions\EinvoicingValidationException;
use Illuminate\Http\Client\Response;

/**
 * Turns a platform error response into a package exception.
 *
 * The messages produced never quote the request headers: those carry the token
 * and the customer-id.
 */
final class ErrorMapper
{
    public function map(Response $response): EinvoicingException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $message = isset($body['statusMessage']) && is_string($body['statusMessage'])
            ? $body['statusMessage']
            : 'The platform returned a '.$status.' error.';

        return match (true) {
            $status === 400 => new EinvoicingValidationException($message, $this->validationErrors($body)),
            $status === 401, $status === 403 => new EinvoicingAuthException($message, $status),
            $status === 409 => new EinvoicingConflictException(
                $message,
                isset($body['code']) && is_string($body['code']) ? $body['code'] : null,
            ),
            $status === 429 => new EinvoicingRateLimitException($message, $this->retryAfter($response)),
            $status >= 500 => new EinvoicingServerException($message, $status),
            default => new EinvoicingServerException($message, $status),
        };
    }

    /**
     * The body of a 400 follows a Zod shape. Its `details` field carries either a
     * single error or a list: both forms are normalised here.
     *
     * @param  array<mixed>  $body
     * @return list<array{path: string, code: string|null, message: string|null}>
     */
    private function validationErrors(array $body): array
    {
        $details = $body['details'] ?? null;

        if (! is_array($details)) {
            return [];
        }

        $items = array_is_list($details) ? $details : [$details];
        $errors = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $path = $item['path'] ?? null;

            $errors[] = [
                'path' => is_array($path) ? implode('.', array_map(strval(...), $path)) : (is_string($path) ? $path : ''),
                'code' => isset($item['code']) && is_string($item['code']) ? $item['code'] : null,
                'message' => isset($item['message']) && is_string($item['message']) ? $item['message'] : null,
            ];
        }

        return $errors;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }
}
