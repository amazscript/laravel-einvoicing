<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Reporting;

use AmazScript\Einvoicing\Enums\TransactionCategory;
use AmazScript\Einvoicing\Enums\VatCategory;
use AmazScript\Einvoicing\Enums\VatPointDate;
use InvalidArgumentException;

/**
 * One B2C transaction to declare to the tax authority.
 *
 * Amounts are held as given and sent as given: no total is recomputed, no rate
 * is inferred. What is declared is what the application says it collected, and
 * a mismatch is a bookkeeping question rather than something to paper over.
 *
 * Build these through the named constructors: they carry the rules the platform
 * enforces, so a call that would be rejected fails here instead.
 */
final readonly class Transaction
{
    private function __construct(
        public TransactionCategory $category,
        /** Amount excluding tax. */
        public float $taxBasis,
        public float $tax,
        /** VAT rate as a percentage: 20.0, not 0.20. */
        public float $rate,
        public string $currency,
        public VatCategory $vatCategory,
        public ?VatPointDate $vatPointDate,
    ) {}

    /**
     * A sale of physical goods.
     */
    public static function goods(
        float $taxBasis,
        float $tax,
        float $rate = 20.0,
        string $currency = 'EUR',
        VatCategory $vatCategory = VatCategory::Standard,
    ): self {
        return new self(TransactionCategory::Goods, $taxBasis, $tax, $rate, $currency, $vatCategory, null);
    }

    /**
     * A service provision.
     *
     * The VAT point date is required rather than defaulted: on services VAT
     * falls due on payment, and guessing that wrong misstates the period the
     * amount belongs to.
     */
    public static function services(
        float $taxBasis,
        float $tax,
        VatPointDate $vatPointDate,
        float $rate = 20.0,
        string $currency = 'EUR',
        VatCategory $vatCategory = VatCategory::Standard,
    ): self {
        return new self(TransactionCategory::Services, $taxBasis, $tax, $rate, $currency, $vatCategory, $vatPointDate);
    }

    /**
     * A transaction outside the scope of VAT.
     */
    public static function nonTaxable(
        float $taxBasis,
        string $currency = 'EUR',
        VatCategory $vatCategory = VatCategory::OutOfScope,
    ): self {
        return new self(TransactionCategory::NonTaxable, $taxBasis, 0.0, 0.0, $currency, $vatCategory, null);
    }

    /**
     * Goods and services in a single declaration.
     */
    public static function mixed(
        float $taxBasis,
        float $tax,
        VatPointDate $vatPointDate,
        float $rate = 20.0,
        string $currency = 'EUR',
        VatCategory $vatCategory = VatCategory::Standard,
    ): self {
        return new self(TransactionCategory::Mixed, $taxBasis, $tax, $rate, $currency, $vatCategory, $vatPointDate);
    }

    /**
     * The shape the platform expects.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->category->needsVatPointDate() && $this->vatPointDate === null) {
            throw new InvalidArgumentException('A service transaction requires a VAT point date.');
        }

        $payload = [
            'currency' => strtoupper($this->currency),
            'categoryCode' => $this->category->value,
            'monetary' => [
                'taxBasisTotalAmount' => ['amount' => $this->taxBasis],
                'taxTotalAmount' => ['amount' => $this->tax],
            ],
            'taxDetails' => [[
                'taxableAmount' => ['amount' => $this->taxBasis],
                'taxAmount' => ['amount' => $this->tax],
                'percent' => $this->rate,
                'code' => $this->vatCategory->value,
            ]],
        ];

        if ($this->vatPointDate instanceof VatPointDate) {
            $payload['taxPaymentOption'] = ['iopCode' => $this->vatPointDate->value];
        }

        return $payload;
    }
}
