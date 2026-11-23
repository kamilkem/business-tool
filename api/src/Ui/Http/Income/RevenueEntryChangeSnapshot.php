<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\RevenueEntrySnapshot;

/** What the entry held before or after a change. */
final readonly class RevenueEntryChangeSnapshot
{
    /**
     * @param list<RevenueEntryChangePayment> $payments
     * @param list<RevenueEntryChangeVatLine> $vatLines
     */
    public function __construct(
        public array $payments,
        /** The sum of the payment lines, in minor units. */
        public int $total,
        public array $vatLines,
        public ?string $note,
    ) {
    }

    public static function of(?RevenueEntrySnapshot $snapshot): ?self
    {
        if (null === $snapshot) {
            return null;
        }

        return new self(
            array_map(static fn (array $line): RevenueEntryChangePayment => new RevenueEntryChangePayment($line['paymentMethodId'], $line['paymentMethodName'], $line['amount']), $snapshot->payments),
            $snapshot->total,
            array_map(static fn (array $line): RevenueEntryChangeVatLine => new RevenueEntryChangeVatLine($line['rate'], $line['gross'], $line['vat']), $snapshot->vatLines),
            $snapshot->note,
        );
    }
}
