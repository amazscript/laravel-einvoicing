<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Storage;

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Writes an invoice's files to the disk configured by the application.
 *
 * Storage is idempotent by content: material already on record, recognised by
 * its digest, is neither rewritten nor duplicated. Replaying a download
 * therefore costs nothing and leaves no orphan files.
 */
final class InvoiceFileStore
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
    ) {}

    public function store(
        InboundInvoice $invoice,
        InvoiceFileKind $kind,
        string $contents,
        ?string $providerFileId = null,
        ?string $filename = null,
        ?string $disk = null,
    ): InvoiceFile {
        $checksum = hash('sha256', $contents);
        $disque = $disk ?? $this->disk();

        $existant = InvoiceFile::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', $kind->value)
            ->where('checksum', $checksum)
            ->first();

        if ($existant instanceof InvoiceFile) {
            return $existant;
        }

        $chemin = $this->path($invoice, $kind, $checksum, $filename);
        $this->filesystems->disk($disque)->put($chemin, $contents);

        return InvoiceFile::query()->create([
            'invoice_id' => $invoice->id,
            'provider_file_id' => $providerFileId,
            'kind' => $kind,
            'disk' => $disque,
            'path' => $chemin,
            'checksum' => $checksum,
        ]);
    }

    /**
     * Reads a file back from the disk it was written to.
     *
     * That disk is the one recorded with the file, not the one configured today:
     * an invoice stored yesterday stays readable if the configuration has
     * changed since.
     */
    public function contents(InvoiceFile $file): string
    {
        return (string) $this->filesystems->disk($file->disk)->get($file->path);
    }

    private function disk(): string
    {
        $disque = $this->config->get('einvoicing.storage.disk');

        return is_string($disque) && $disque !== '' ? $disque : 'local';
    }

    /**
     * The path derives from the invoice id and the digest, never from the
     * transmitted filename: that name comes from outside and could climb the
     * tree. Only its extension is reused, after filtering.
     */
    private function path(InboundInvoice $invoice, InvoiceFileKind $kind, string $checksum, ?string $filename): string
    {
        $racine = $this->config->get('einvoicing.storage.path');
        $racine = is_string($racine) && $racine !== '' ? trim($racine, '/') : 'einvoicing';

        $extension = $this->extension($filename, $kind);

        return sprintf(
            '%s/%s/%s-%s%s',
            $racine,
            $invoice->id,
            strtolower($kind->value),
            substr($checksum, 0, 12),
            $extension,
        );
    }

    private function extension(?string $filename, InvoiceFileKind $kind): string
    {
        if ($filename !== null) {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1) {
                return '.'.$extension;
            }
        }

        return match ($kind) {
            InvoiceFileKind::Xml => '.xml',
            InvoiceFileKind::ReadablePdf => '.pdf',
            InvoiceFileKind::Attachment => '',
        };
    }
}
