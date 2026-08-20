<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\OutboundInvoiceGateway;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;

/**
 * Sends invoices through the Iopole platform.
 *
 * The endpoint takes multipart/form-data with a single `file` part, and nothing
 * else: no recipient field, no idempotency key. The recipient is read by the
 * platform from the document itself, which is why the package cannot check it
 * without parsing a format it has no business parsing.
 */
final class IopoleOutboundInvoiceGateway implements OutboundInvoiceGateway
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function send(string $filePath, string $fileName): string
    {
        $reponse = $this->client->upload(Endpoints::sendInvoice(), 'file', $filePath, $fileName);

        $id = $reponse['id'] ?? null;

        if (! is_string($id) || $id === '') {
            // A 201 without an identifier leaves the invoice in limbo: it may
            // well have been accepted, and nothing could ever track it.
            throw new EinvoicingServerException('Platform accepted the invoice without returning an identifier.');
        }

        return $id;
    }
}
