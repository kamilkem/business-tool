<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Analysis\AnalysisAmounts;

/** Spend that belongs to no category or to no product, and how many of the things it is made of. */
final readonly class PurchaseAnalysisBucket
{
    public function __construct(
        /** Minor units. */
        public int $net,
        public int $gross,
        /** Products for `uncategorized`, purchased items for `notAssigned`. */
        public int $count,
    ) {
    }

    public static function fromDomain(AnalysisAmounts $amounts, int $count): self
    {
        return new self($amounts->net, $amounts->gross, $count);
    }
}
