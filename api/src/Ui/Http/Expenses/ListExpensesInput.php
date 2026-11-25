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
 * validates it, so a bad date, period or seller is a 422 with violations like any other Input.
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
        /** A part of the seller's name or tax ID. */
        #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_NAME_LENGTH)]
        public ?string $seller = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, ListPurchaseInvoicesQuery::MAX_DAYS);
    }
}
