<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * One line of a daily report's VAT breakdown, as printed: the gross sales at a rate and the VAT in
 * them, in minor units (ADR 0022 §6). VAT is not recomputed; RevenueEntry checks the line against
 * the country's rates when it takes it.
 */
final readonly class VatAmount
{
    public function __construct(
        public string $rate,
        public int $gross,
        public int $vat,
    ) {
    }

    public function net(): int
    {
        return $this->gross - $this->vat;
    }
}
