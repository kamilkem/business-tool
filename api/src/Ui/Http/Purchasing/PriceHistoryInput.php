<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /products/{id}/price-history. ProductPriceHistoryProvider builds it from the
 * query parameters and validates it, so a bad date or period is a 422 with violations like any other Input.
 */
final readonly class PriceHistoryInput
{
    public function __construct(
        /** First issue date, inclusive (YYYY-MM-DD). */
        #[Assert\Date]
        public ?string $from = null,
        /** Last issue date, inclusive (YYYY-MM-DD). */
        #[Assert\Date]
        public ?string $to = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        BusinessPeriodRules::validate($context, $this->from, $this->to, \PHP_INT_MAX);
    }
}
