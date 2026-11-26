<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** What the model did to the items of a run (ADR 0046 §6): how many it went through and how many of them ended `assigned` or `suggested`. */
final readonly class ModelOutcomeCounts
{
    public function __construct(
        public int $interpreted,
        public int $assigned,
        public int $suggested,
    ) {
    }

    /** The items the model went through and found no product for. */
    public function foundNothing(): int
    {
        return max(0, $this->interpreted - $this->assigned - $this->suggested);
    }
}
