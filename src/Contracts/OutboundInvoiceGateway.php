<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Exceptions\EinvoicingException;

/**
 * Hands a ready-made invoice document to the platform.
 *
 * The package never builds the document: producing a valid Factur-X, UBL or CII
 * is a trade of its own, and the accredited platform is the one answerable for
 * it. What arrives here is a file the host application already has.
 */
interface OutboundInvoiceGateway
{
    /**
     * Sends a document and returns the identifier the platform assigns it.
     *
     * @param  string  $filePath  Absolute path to a PDF or XML invoice
     * @param  string  $fileName  Name carried in the multipart part
     *
     * @throws EinvoicingException when the platform refuses it
     */
    public function send(string $filePath, string $fileName): string;
}
