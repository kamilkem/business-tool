<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Application\Query\ListCashMovements\ListCashMovementsQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /cash-movements. CashMovementCollectionProvider builds it from the query
 * parameters and validates it, so a bad date or period is a 422 with violations like any other Input.
 */
final readonly class ListCashMovementsInput
{
    public function __construct(
        /** First business date, inclusive (YYYY-MM-DD in the organization's time zone). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $from,
        /** Last business date, inclusive (YYYY-MM-DD in the organization's time zone). */
        #[Assert\NotBlank]
        #[Assert\Date]
        public ?string $to,
        /** Only the movements on this storage. */
        #[Assert\Uuid]
        public ?string $storageId = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, ListCashMovementsQuery::MAX_DAYS);
    }
}
