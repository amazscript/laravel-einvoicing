<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\ReportingGateway;
use AmazScript\Einvoicing\Enums\VatRegime;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Reporting\ReportFolder;
use AmazScript\Einvoicing\Reporting\Transaction;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\LazyCollection;
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

    /**
     * Withdraws a declaration.
     *
     * **Not available yet.** The platform answers 501 here as it does on the
     * update endpoints, so nothing declared can currently be taken back or
     * amended. Kept because the call is correct and will work the day the
     * platform implements it; verified against the real API on 2026-08-20.
     *
     * Until then, treat every declaration as final and check the figures before
     * sending them.
     */
    public function deleteTransaction(string $transactionId): void
    {
        $this->gateway->deleteTransaction($transactionId);
    }

    public function deletePayment(string $paymentId): void
    {
        $this->gateway->deletePayment($paymentId);
    }

    /**
     * The reporting periods held for this tenant.
     *
     * Declarations accumulate into a period that closes on its own; once
     * closed, nothing more goes in.
     *
     * The starting month is required — the platform rejects the call without
     * it — and both bounds are months, not days: a reporting period is never
     * finer than that.
     *
     * @return LazyCollection<int, ReportFolder>
     */
    public function reports(DateTimeInterface $from, ?DateTimeInterface $to = null): LazyCollection
    {
        return $this->gateway
            ->reports('0002', $this->siren(), $from->format('Y-m'), $to?->format('Y-m'))
            ->map(fn (array $ligne): ReportFolder => $this->toFolder($ligne));
    }

    /**
     * @param  array<mixed>  $ligne
     */
    private function toFolder(array $ligne): ReportFolder
    {
        $regime = $ligne['vatRegime'] ?? null;

        return new ReportFolder(
            id: (string) ($ligne['id'] ?? ''),
            state: $this->stringOrNull($ligne['state'] ?? null),
            status: $this->stringOrNull($ligne['status'] ?? null),
            transactionType: $this->stringOrNull($ligne['transactionType'] ?? null),
            vatRegime: is_string($regime) ? VatRegime::tryFrom($regime) : null,
            startDate: $this->dateOrNull($ligne['startDate'] ?? null),
            endDate: $this->dateOrNull($ligne['endDate'] ?? null),
            autoCloseDate: $this->dateOrNull($ligne['autoCloseDate'] ?? null),
        );
    }

    private function stringOrNull(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    /**
     * Dates come as plain days or as timestamps depending on the field; only
     * the day is kept, which is all a reporting period is expressed in.
     */
    private function dateOrNull(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($valeur, 0, 10));

        return $date === false ? null : $date;
    }
}
