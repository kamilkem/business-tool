<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

/** What was spent on a category in a period: on products directly in it (`own`) and with every subcategory's (`total`). */
final readonly class CategorySpend
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public AnalysisAmounts $own,
        public AnalysisAmounts $total,
    ) {
    }
}
