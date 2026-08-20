<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

/**
 * The Iopole API paths used for receiving invoices (v0.1).
 *
 * The API version is encapsulated here and nowhere else: a consumer of the
 * package never has to know that one endpoint lives in /v1 and another in /v1.1.
 *
 * Only receiving-side paths appear here. Issuing, e-reporting and onboarding are
 * outside v0.1.
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

    /**
     * Declares B2C transactions for a company.
     */
    public static function reportTransactions(string $scheme, string $value): string
    {
        return '/v1/reporting/transaction/scheme/'.rawurlencode($scheme).'/value/'.rawurlencode($value);
    }

    /**
     * Declares payments collected against reported transactions.
     */
    public static function reportPaymentForTransaction(string $scheme, string $value): string
    {
        return '/v1/reporting/payment/transaction/scheme/'.rawurlencode($scheme).'/value/'.rawurlencode($value);
    }

    public static function reportingTransaction(string $transactionId): string
    {
        return '/v1/reporting/transaction/'.rawurlencode($transactionId);
    }

    public static function reportingPayment(string $paymentId): string
    {
        return '/v1/reporting/payment/'.rawurlencode($paymentId);
    }

    /**
     * The reporting periods held for a company.
     */
    public static function reports(string $scheme, string $value): string
    {
        return '/v1/reporting/report/scheme/'.rawurlencode($scheme).'/value/'.rawurlencode($value);
    }

    /**
     * Declares a legal unit.
     */
    public static function declareLegalUnit(): string
    {
        return '/v1/config/business/entity/legalunit';
    }

    /**
     * Sets an entity's VAT regime, which e-reporting requires.
     */
    public static function configureEntity(string $businessEntityId): string
    {
        return '/v1/config/business/entity/'.rawurlencode($businessEntityId).'/configure';
    }

    /**
     * Registers an identifier on a network — what makes an address reachable.
     */
    public static function registerOnNetwork(string $scheme, string $value, string $network): string
    {
        return '/v1/config/business/entity/identifier'
            .'/scheme/'.rawurlencode($scheme)
            .'/value/'.rawurlencode($value)
            .'/network/'.rawurlencode($network);
    }

    /**
     * The buyer's answer about an invoice it received.
     */
    public static function postInvoiceStatus(string $invoiceId): string
    {
        return '/v1/invoice/'.rawurlencode($invoiceId).'/status';
    }

    /**
     * Hands over an invoice document. multipart/form-data, single `file` part.
     */
    public static function sendInvoice(): string
    {
        return '/v1/invoice';
    }

    public static function businessEntities(): string
    {
        return '/v1/config/business/entity';
    }

    public static function businessEntity(string $businessEntityId): string
    {
        return '/v1/config/business/entity/'.rawurlencode($businessEntityId);
    }

    public static function retryStrategy(): string
    {
        return '/v1/config/retry/strategy';
    }

    /**
     * Invoice search. This endpoint lives in v1.1 while the rest is in v1 —
     * precisely what this class exists to hide.
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
