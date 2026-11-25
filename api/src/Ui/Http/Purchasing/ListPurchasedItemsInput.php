<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\ListPurchasedItems\ListPurchasedItemsQuery;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /purchased-items. PurchasedItemListProvider builds it from the query
 * parameters and validates it, so a bad filter is a 422 with violations like any other Input. The
 * page and its size are text here: they come from the query string, and a non-number is a violation.
 */
final readonly class ListPurchasedItemsInput
{
    /** @param list<string> $status */
    public function __construct(
        /** Only the items in any of these states. */
        #[Assert\All(new Assert\Choice(callback: 'statuses'))]
        public array $status = [],
        /** Only the items linked to this product. */
        #[Assert\Uuid]
        public ?string $productId = null,
        /** First issue date, inclusive (YYYY-MM-DD). */
        #[Assert\Date]
        public ?string $from = null,
        /** Last issue date, inclusive (YYYY-MM-DD). */
        #[Assert\Date]
        public ?string $to = null,
        /** A text the item's name contains. */
        #[Assert\Length(max: 512)]
        public ?string $search = null,
        #[Assert\Regex('/^\d+$/')]
        #[Assert\Range(min: 1, max: 1_000_000)]
        public ?string $page = null,
        #[Assert\Regex('/^\d+$/')]
        #[Assert\Range(min: 1, max: ListPurchasedItemsQuery::MAX_PAGE_SIZE)]
        public ?string $itemsPerPage = null,
    ) {
    }

    /** @return list<string> the values of PurchasedItemStatus */
    public static function statuses(): array
    {
        return array_map(static fn (PurchasedItemStatus $status): string => $status->value, PurchasedItemStatus::cases());
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, \PHP_INT_MAX);
    }
}
