<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/** A VAT rate as a fiscal printer labels it ("23", "zw"), and whether sales at it carry no VAT. */
final readonly class VatRate
{
    public function __construct(
        public string $code,
        public bool $zeroVat,
    ) {
    }
}
