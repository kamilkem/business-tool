<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\SuggestExpenseCategory;

use App\Shared\Application\Query\QueryInterface;

/** The category to suggest for an expense of this seller (#55), e.g. while one is entered by hand. */
final readonly class SuggestExpenseCategoryQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public ?string $sellerTaxId,
        public ?string $sellerName,
    ) {
    }
}
