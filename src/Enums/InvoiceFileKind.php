<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

enum InvoiceFileKind: string
{
    case Xml = 'XML';
    case ReadablePdf = 'READABLE_PDF';
    case Attachment = 'ATTACHMENT';
}
