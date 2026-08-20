<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\ReportingGateway;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use Illuminate\Support\LazyCollection;

/**
 * E-reporting through the Iopole platform.
 *
 * Everything here is JSON: unlike invoices, nothing has to be produced in a tax
 * format, so the package can carry the whole exchange rather than half of it.
 */
final class IopoleReportingGateway implements ReportingGateway
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function reportTransactions(string $scheme, string $value, array $payload): string
    {
        return $this->identifierOf(
            $this->client->post(Endpoints::reportTransactions($scheme, $value), $payload)
        );
    }

    public function reportPayment(string $scheme, string $value, array $payload): string
    {
        return $this->identifierOf(
            $this->client->post(Endpoints::reportPaymentForTransaction($scheme, $value), $payload)
        );
    }

    public function deleteTransaction(string $transactionId): void
    {
        $this->client->delete(Endpoints::reportingTransaction($transactionId));
    }

    public function deletePayment(string $paymentId): void
    {
        $this->client->delete(Endpoints::reportingPayment($paymentId));
    }

    /**
     * @return LazyCollection<int, array<mixed>>
     */
    public function reports(string $scheme, string $value, ?string $from = null, ?string $to = null): LazyCollection
    {
        return $this->client->paginate(Endpoints::reports($scheme, $value), array_filter([
            'from' => $from,
            'to' => $to,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param  array<mixed>  $reponse
     */
    private function identifierOf(array $reponse): string
    {
        $id = $reponse['id'] ?? null;

        if (! is_string($id) || $id === '') {
            // A declaration the platform accepted but did not name cannot be
            // corrected later, and a correction is exactly what a wrong figure
            // will need.
            throw new EinvoicingServerException('Platform accepted the declaration without returning an identifier.');
        }

        return $id;
    }
}
