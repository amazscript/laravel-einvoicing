<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Storage;

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Écrit les fichiers d'une facture sur le disque configuré par l'application.
 *
 * Le stockage est idempotent : un contenu déjà présent, reconnu à son empreinte,
 * n'est ni réécrit ni dupliqué. Rejouer un téléchargement ne coûte donc rien et
 * ne laisse pas de fichiers orphelins.
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
     * Relit un fichier depuis le disque où il a été rangé.
     *
     * Le disque est celui enregistré avec le fichier, pas celui configuré
     * aujourd'hui : une facture rangée hier reste lisible si la configuration a
     * changé depuis.
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
     * Le chemin est dérivé de l'identifiant de facture et de l'empreinte, jamais
     * du nom d'origine : celui-ci vient de l'extérieur et pourrait remonter
     * l'arborescence. Seule l'extension en est reprise, et filtrée.
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
