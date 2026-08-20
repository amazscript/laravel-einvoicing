<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * A view of an inbound request, reduced to what authenticating it requires.
 *
 * The subtlety lies entirely in the checksum source:
 *
 *   - for `application/json`, the whole raw body;
 *   - for `multipart/form-data`, the file field content **alone**.
 *
 * More importantly, in multipart the raw body is no longer readable: PHP has
 * already consumed it to populate $_POST and $_FILES, so php://input returns an
 * empty string. Verified on a real request. The uploaded temporary file is read
 * instead, which yields exactly the expected source.
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

    /**
     * Rebuilds a request from a stored payload, for routing only.
     *
     * Replaying an event has nothing but the body: headers and raw bytes were
     * never kept. Signature fields are therefore left empty — this object must
     * never be handed to signature verification, only to routing.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromStoredPayload(array $payload): self
    {
        return new self(
            headers: [],
            method: 'POST',
            pathWithQuery: '',
            checksumSource: '',
            isMultipart: false,
            rawBody: '',
            payload: $payload,
        );
    }

    public static function fromRequest(Request $request, ?string $canonicalPath = null): self
    {
        $file = self::firstUploadedFile($request);

        // The Content-Type header alone is not trusted: behind a proxy, or
        // depending on how the request is rebuilt, it may be missing. The
        // presence of an uploaded file is the more reliable signal.
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
        // The platform sends the invoice in a field named "file". That one is
        // preferred, without excluding another name: the signed body is still
        // the file.
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
