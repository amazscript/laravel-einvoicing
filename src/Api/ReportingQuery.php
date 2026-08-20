<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\ReportingGateway;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Reporting\Transaction;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Declares a tenant's B2C activity to the tax authority.
 *
 * E-invoicing carries what passes between businesses; e-reporting covers the
 * rest — sales to consumers, and the payments collected on services. Neither
 * travels the network, so nothing else would ever declare them.
 */
final class ReportingQuery
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly ReportingGateway $gateway,
    ) {}

    /**
     * Declares the transactions recorded on a given day.
     *
     * The platform requires one call per company and per batch; sending the
     * same day twice declares it twice, so batching is the caller's to control.
     *
     * @param  list<Transaction>  $transactions
     * @param  string|null  $registerId  the till or point of sale, when there is one
     * @return string the identifier of the declaration, needed to correct it later
     */
    public function reportTransactions(
        DateTimeInterface $date,
        array $transactions,
        ?string $registerId = null,
        ?string $storeId = null,
        ?string $closureId = null,
    ): string {
        if ($transactions === []) {
            // Declaring nothing is not the same as declaring zero, and the
            // difference matters to a tax authority.
            throw new InvalidArgumentException('Nothing to report: pass at least one transaction.');
        }

        $payload = array_filter([
            'transactionDate' => $date->format('Y-m-d'),
            'registerId' => $registerId,
            'storeId' => $storeId,
            'closureId' => $closureId,
        ], static fn (mixed $v): bool => $v !== null);

        $payload['transactions'] = array_map(
            static fn (Transaction $t): array => $t->toPayload(),
            $transactions,
        );

        return $this->gateway->reportTransactions('0002', $this->siren(), $payload);
    }

    /**
     * Declares a payment collected against reported transactions.
     *
     * On services VAT falls due when the money arrives, not when the work is
     * done, which is why this is reported separately.
     */
    public function reportPayment(
        DateTimeInterface $date,
        float $amount,
        string $currency = 'EUR',
        ?string $reference = null,
    ): string {
        return $this->gateway->reportPayment('0002', $this->siren(), [
            'transaction' => array_filter([
                'paymentDate' => $date->format('Y-m-d'),
                'amount' => ['amount' => $amount, 'currency' => strtoupper($currency)],
                'reference' => $reference,
            ], static fn (mixed $v): bool => $v !== null),
        ]);
    }

    /**
     * The declaring company, as nine digits.
     */
    private function siren(): string
    {
        $chiffres = preg_replace('/\D/', '', $this->tenant->siren) ?? '';

        if (strlen($chiffres) !== 9) {
            throw new InvalidArgumentException('This tenant has no usable SIREN to report under.');
        }

        return $chiffres;
    }
}
