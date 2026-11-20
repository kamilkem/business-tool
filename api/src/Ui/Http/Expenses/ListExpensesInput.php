<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesQuery;
use App\Expenses\Domain\Model\PurchaseInvoice;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /expenses. ExpenseListProvider builds it from the query parameters and
 * validates it, so a bad date, period or filter is a 422 with violations like any other Input.
 */
final readonly class ListExpensesInput
{
    public function __construct(
        /** First issue date, inclusive (YYYY-MM-DD). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $from,
        /** Last issue date, inclusive (YYYY-MM-DD). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $to,
        /** Only expenses of this category. */
        #[Assert\Uuid]
        public ?string $categoryId = null,
        /** "true" or "1": only expenses without a category. */
        #[Assert\Choice(choices: ['true', 'false', '1', '0'])]
        public ?string $uncategorized = null,
        /** A part of the seller's name or tax ID. */
        #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_NAME_LENGTH)]
        public ?string $seller = null,
    ) {
    }

    public function onlyUncategorized(): bool
    {
        return \in_array($this->uncategorized, ['true', '1'], true);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, ListPurchaseInvoicesQuery::MAX_DAYS);

        if (null !== $this->categoryId && $this->onlyUncategorized()) {
            $context->buildViolation('Filter either by a category or by uncategorized expenses, not both.')->atPath('uncategorized')->addViolation();
        }
    }
}
