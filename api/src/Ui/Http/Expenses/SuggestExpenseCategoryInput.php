<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Domain\Model\PurchaseInvoice;
use Symfony\Component\Validator\Constraints as Assert;

/** The query string of GET /expense-category-suggestion, validated by ExpenseCategorySuggestionProvider (422). */
final readonly class SuggestExpenseCategoryInput
{
    public function __construct(
        #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_TAX_ID_LENGTH)]
        public ?string $sellerTaxId = null,
        #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_NAME_LENGTH)]
        public ?string $sellerName = null,
    ) {
    }
}
