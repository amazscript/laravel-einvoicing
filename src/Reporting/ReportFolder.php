<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Reporting;

use AmazScript\Einvoicing\Enums\VatRegime;
use DateTimeImmutable;

/**
 * A reporting period as the platform holds it.
 *
 * Declarations accumulate into a folder covering a period, which closes on its
 * own and is then submitted to the tax authority. Once closed, nothing more
 * goes in — which is why the closing date is worth watching.
 */
final readonly class ReportFolder
{
    public function __construct(
        public string $id,
        /** OPEN or CLOSED. Left as a string: only two values, both self-evident. */
        public ?string $state,
        /** PENDING, SUBMITTED, ACCEPTED or REJECTED. */
        public ?string $status,
        /** INITIAL or CORRECTIVE. */
        public ?string $transactionType,
        public ?VatRegime $vatRegime,
        public ?DateTimeImmutable $startDate,
        public ?DateTimeImmutable $endDate,
        /** When the folder closes by itself. Past it, nothing more can be declared. */
        public ?DateTimeImmutable $autoCloseDate,
    ) {}

    /**
     * Whether declarations can still be added to this period.
     */
    public function isOpen(): bool
    {
        return $this->state === 'OPEN';
    }

    /**
     * Whether the tax authority refused it.
     */
    public function wasRejected(): bool
    {
        return $this->status === 'REJECTED';
    }
}
