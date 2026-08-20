<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Declares to the tax authority what e-invoicing does not carry.
 *
 * Sales to consumers travel no network, so without this the authority sees
 * nothing of them. Reporting is how that gap is closed.
 */
interface ReportingGateway
{
    /**
     * Declares a batch of B2C transactions for one company.
     *
     * @param  array<string, mixed>  $payload
     * @return string the identifier the platform assigns the declaration
     */
    public function reportTransactions(string $scheme, string $value, array $payload): string;

    /**
     * Declares payments collected against reported transactions.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reportPayment(string $scheme, string $value, array $payload): string;
}
