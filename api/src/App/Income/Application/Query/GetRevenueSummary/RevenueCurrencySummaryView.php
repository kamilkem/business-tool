<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueSummary;

use App\Income\Application\Query\GetRevenueEntry\RevenuePaymentLineView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatRates;

/**
 * The totals of a period's entries in one currency, in its minor units (ADR 0010). As for one entry,
 * the VAT totals need not match the payments total (ADR 0022 §6).
 */
final readonly class RevenueCurrencySummaryView
{
    /**
     * @param list<RevenuePaymentLineView>   $payments  per payment method used, in the organization's order
     *                                                 (PaymentMethod::compare()), deactivated ones last
     * @param list<RevenueVatLineView>       $vatLines  per VAT rate used, highest rate first, exempt last
     * @param list<RevenueRegisterTotalView> $registers per register with entries, by name
     */
    public function __construct(
        /** ISO 4217 code. */
        public string $currency,
        /** The sum of all payment lines. */
        public int $total,
        public int $entryCount,
        public array $payments,
        public array $vatLines,
        public int $vatGrossTotal,
        public int $vatTotal,
        public array $registers,
    ) {
    }

    /** @param list<RevenueEntry> $entries entries in $currency */
    public static function of(string $currency, array $entries): self
    {
        /** @var array<string, PaymentAmount> $byMethod */
        $byMethod = [];
        $byRate = [];
        $byRegister = [];
        foreach ($entries as $entry) {
            foreach ($entry->getPaymentLines() as $line) {
                $methodId = $line->method->getId()->toRfc4122();
                $byMethod[$methodId] = new PaymentAmount($line->method, ($byMethod[$methodId]->amount ?? 0) + $line->amount);
            }
            foreach ($entry->getVatLines() as $line) {
                [$gross, $vat] = $byRate[$line->rate] ?? [0, 0];
                $byRate[$line->rate] = [$gross + $line->gross, $vat + $line->vat];
            }
            $register = $entry->getRegister();
            $key = $register->getId()->toRfc4122();
            [$total, $count] = isset($byRegister[$key]) ? [$byRegister[$key]->total, $byRegister[$key]->entryCount] : [0, 0];
            $byRegister[$key] = new RevenueRegisterTotalView($key, $register->getName(), $total + $entry->getTotal(), $count + 1);
        }

        $methodTotals = array_values($byMethod);
        usort($methodTotals, static fn (PaymentAmount $a, PaymentAmount $b): int => PaymentMethod::compare($a->method, $b->method));
        $payments = array_map(RevenuePaymentLineView::of(...), $methodTotals);

        $rates = array_map(strval(...), array_keys($byRate));
        usort($rates, VatRates::compare(...));
        $vatLines = array_map(static fn (string $rate): RevenueVatLineView => new RevenueVatLineView($rate, $byRate[$rate][0], $byRate[$rate][1], $byRate[$rate][0] - $byRate[$rate][1]), $rates);

        $registers = array_values($byRegister);
        usort($registers, static fn (RevenueRegisterTotalView $a, RevenueRegisterTotalView $b): int => [$a->registerName, $a->registerId] <=> [$b->registerName, $b->registerId]);

        return new self(
            $currency,
            array_sum(array_map(static fn (RevenuePaymentLineView $line): int => $line->amount, $payments)),
            \count($entries),
            $payments,
            $vatLines,
            array_sum(array_map(static fn (RevenueVatLineView $line): int => $line->gross, $vatLines)),
            array_sum(array_map(static fn (RevenueVatLineView $line): int => $line->vat, $vatLines)),
            $registers,
        );
    }
}
