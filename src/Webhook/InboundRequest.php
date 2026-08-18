<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Vue d'une requête entrante, réduite à ce qui sert à l'authentifier.
 *
 * Toute la subtilité tient dans la source du checksum :
 *
 *   - en `application/json`, c'est le corps brut intégral ;
 *   - en `multipart/form-data`, c'est le contenu du champ fichier **seul**.
 *
 * Et surtout, en multipart le corps brut n'est plus lisible : PHP l'a déjà
 * consommé pour peupler $_POST et $_FILES, si bien que php://input renvoie une
 * chaîne vide. Vérifié sur une requête réelle. On passe donc par le fichier
 * temporaire, ce qui donne exactement la source attendue.
 */
final class InboundRequest
{
    private function __construct(
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $method,
        public readonly string $pathWithQuery,
        public readonly string $checksumSource,
        public readonly bool $isMultipart,
        public readonly string $rawBody,
        /** @var array<string, mixed> */
        public readonly array $payload,
    ) {}

    public static function fromRequest(Request $request, ?string $canonicalPath = null): self
    {
        $file = self::firstUploadedFile($request);

        // On ne se fie pas au seul en-tête Content-Type : derrière un proxy, ou
        // selon la façon dont la requête est reconstruite, il peut manquer. La
        // présence d'un fichier uploadé est le signe le plus fiable.
        $multipart = $file instanceof UploadedFile
            || str_contains($request->headers->get('Content-Type') ?? '', 'multipart/form-data');

        $rawBody = $multipart ? '' : $request->getContent();

        return new self(
            headers: self::normalizedHeaders($request),
            method: $request->method(),
            pathWithQuery: $canonicalPath !== null && $canonicalPath !== ''
                ? $canonicalPath
                : self::pathWithQuery($request),
            checksumSource: $multipart
                ? ($file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : '')
                : $rawBody,
            isMultipart: $multipart,
            rawBody: $rawBody,
            payload: self::payload($request, $multipart, $rawBody),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function normalizedHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    private static function pathWithQuery(Request $request): string
    {
        $query = $request->getQueryString();

        return $request->getPathInfo().($query !== null && $query !== '' ? '?'.$query : '');
    }

    private static function firstUploadedFile(Request $request): ?UploadedFile
    {
        // La plateforme transmet la facture dans un champ nommé « file ». On le
        // privilégie, sans exclure un autre nom : le corps signé reste le fichier.
        $files = $request->allFiles();
        $candidat = $files['file'] ?? (count($files) > 0 ? reset($files) : null);

        if (is_array($candidat)) {
            $candidat = count($candidat) > 0 ? reset($candidat) : null;
        }

        return $candidat instanceof UploadedFile ? $candidat : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Request $request, bool $multipart, string $rawBody): array
    {
        if ($multipart) {
            return $request->except(array_keys($request->allFiles()));
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
