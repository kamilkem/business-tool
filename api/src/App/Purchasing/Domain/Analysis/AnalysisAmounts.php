<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

/** A spend in minor units of the organization's currency on both bases (ADR 0041 §6); a correction's rows count negative. */
final readonly class AnalysisAmounts
{
    public function __construct(
        public int $net,
        public int $gross,
    ) {
    }
}
