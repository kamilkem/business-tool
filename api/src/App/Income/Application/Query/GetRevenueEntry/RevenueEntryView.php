<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;

/**
 * A register's daily report with the totals computed (ADR 0022): amounts are integer minor units of
 * `currency` (ADR 0010). The VAT totals need not match the payments total; the admin shows the gap.
 */
final readonly class RevenueEntryView
{
    /**
     * @param list<RevenuePaymentLineView> $payments in the organization's order of methods
     * @param list<RevenueVatLineView>     $vatLines highest rate first, exempt last
     */
    public function __construct(
        public string $id,
        public string $registerId,
        public string $registerName,
        public string $businessDate,
        public string $currency,
        public array $payments,
        public array $vatLines,
        public int $paymentsTotal,
        public int $vatGrossTotal,
        public int $vatTotal,
        public ?string $note,
        /** The end-of-day close the entry is booked from (ADR 0033 §9); null for a manual entry. */
        public ?string $dayCloseId,
        public string $createdBy,
        public \DateTimeImmutable $createdAt,
        public ?string $updatedBy,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntry(RevenueEntry $entry): self
    {
        $vatLines = $entry->getVatLines();

        return new self(
            $entry->getId()->toRfc4122(),
            $entry->getRegister()->getId()->toRfc4122(),
            $entry->getRegister()->getName(),
            $entry->getBusinessDate()->toString(),
            $entry->getCurrency(),
            array_map(RevenuePaymentLineView::of(...), $entry->getPaymentLines()),
            array_map(static fn (VatAmount $line): RevenueVatLineView => new RevenueVatLineView($line->rate, $line->gross, $line->vat, $line->net()), $vatLines),
            $entry->getTotal(),
            array_sum(array_map(static fn (VatAmount $line): int => $line->gross, $vatLines)),
            array_sum(array_map(static fn (VatAmount $line): int => $line->vat, $vatLines)),
            $entry->getNote(),
            $entry->getDayCloseId()?->toRfc4122(),
            $entry->getCreatedBy()->toRfc4122(),
            $entry->getCreatedAt(),
            $entry->getUpdatedBy()?->toRfc4122(),
            $entry->getUpdatedAt(),
        );
    }
}
