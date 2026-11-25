<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetPurchaseAnalysis\GetPurchaseAnalysisQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /purchase-analysis. PurchaseAnalysisProvider builds it from the query
 * parameters and validates it, so a bad date or period is a 422 with violations like any other Input.
 */
final readonly class PurchaseAnalysisInput
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
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, GetPurchaseAnalysisQuery::MAX_DAYS);
    }
}
