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
 * Traduit une réponse d'erreur de la plateforme en exception du package.
 *
 * Les messages produits ne reprennent jamais les en-têtes de la requête : ceux-ci
 * portent le jeton et le customer-id.
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
            : 'La plateforme a renvoyé une erreur '.$status.'.';

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
     * Le corps d'un 400 suit le format Zod. Le champ `details` porte tantôt une
     * erreur unique, tantôt une liste : les deux formes sont normalisées ici.
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
