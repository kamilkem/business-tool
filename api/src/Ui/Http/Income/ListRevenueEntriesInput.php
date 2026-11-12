<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\ListRevenueEntries\ListRevenueEntriesQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The query string of GET /revenue-entries. RevenueEntryCollectionProvider builds it from the query
 * parameters and validates it, so a bad date or period is a 422 with violations like any other Input.
 */
final readonly class ListRevenueEntriesInput
{
    public function __construct(
        /** First business date, inclusive (YYYY-MM-DD). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $from,
        /** Last business date, inclusive (YYYY-MM-DD). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $to,
        /** Only this register's entries. */
        #[Assert\Uuid]
        public ?string $registerId = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        RevenuePeriodRules::validate($context, $this->from, $this->to, ListRevenueEntriesQuery::MAX_DAYS);
    }
}
