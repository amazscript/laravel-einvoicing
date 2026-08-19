<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

/**
 * Chemins de l'API Iopole utilisés par la réception (v0.1).
 *
 * La version d'API est encapsulée ici et nulle part ailleurs : le consommateur
 * du package n'a jamais à savoir qu'un endpoint est en /v1 et un autre en /v1.1.
 *
 * Seuls les chemins du périmètre de réception figurent ici. L'émission, l'e-reporting
 * et l'onboarding sont hors v0.1.
 */
final class Endpoints
{
    public static function customerId(): string
    {
        return '/v1/config/customer/id';
    }

    public static function webhooks(): string
    {
        return '/v1/config/webhook';
    }

    public static function webhook(string $webhookId): string
    {
        return '/v1/config/webhook/'.rawurlencode($webhookId);
    }

    public static function retryStrategy(): string
    {
        return '/v1/config/retry/strategy';
    }

    /**
     * Recherche de factures. Cet endpoint vit en v1.1 quand le reste est en v1 :
     * c'est précisément ce que cette classe existe pour masquer.
     */
    public static function searchInvoices(): string
    {
        return '/v1.1/invoice/search';
    }

    public static function invoicesNotSeen(): string
    {
        return '/v1/invoice/notSeen';
    }

    public static function statusesNotSeen(): string
    {
        return '/v1/invoice/status/notSeen';
    }

    public static function markInvoiceAsSeen(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/markAsSeen';
    }

    public static function markStatusAsSeen(string $statusId): string
    {
        return '/v1/invoice/status/'.rawurlencode($statusId).'/markAsSeen';
    }

    public static function invoice(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId);
    }

    public static function downloadInvoice(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/download';
    }

    public static function downloadReadableInvoice(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/download/readable';
    }

    public static function invoiceFiles(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/files';
    }

    public static function invoiceAttachments(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/files/attachments';
    }

    public static function downloadFile(string $fileId): string
    {
        return '/v1/invoice/file/'.rawurlencode($fileId).'/download';
    }

    public static function directoryFrenchSearch(): string
    {
        return '/v1/directory/french';
    }
}
