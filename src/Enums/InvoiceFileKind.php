<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Nature of a file attached to an invoice: the original document, a
 * human-readable rendering, or a supporting attachment.
 */
enum InvoiceFileKind: string
{
    case Xml = 'XML';
    case ReadablePdf = 'READABLE_PDF';
    case Attachment = 'ATTACHMENT';
}
