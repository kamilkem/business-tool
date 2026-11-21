<?php

declare(strict_types=1);

namespace Ui\Http\Reporting;

use App\Reporting\Application\Query\GetBusinessSummary\GetBusinessSummaryQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The query string of GET /business-summary. BusinessSummaryProvider builds it from the query
 * parameters and validates it, so a bad month or period is a 422 with violations. A month that has
 * not started depends on the organization's time zone and is the domain's (422 as well).
 */
final readonly class BusinessSummaryInput
{
    private const string MONTH = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(
        /** First month, inclusive (YYYY-MM). */
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::MONTH, message: 'This is not a month (YYYY-MM).')]
        public ?string $from,
        /** Last month, inclusive (YYYY-MM). */
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::MONTH, message: 'This is not a month (YYYY-MM).')]
        public ?string $to,
    ) {
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if (null === $this->from || null === $this->to || 1 !== preg_match(self::MONTH, $this->from) || 1 !== preg_match(self::MONTH, $this->to)) {
            return; // the field constraints report it
        }

        $months = self::index($this->to) - self::index($this->from) + 1;
        if ($months < 1) {
            $context->buildViolation('The last month must not be before the first month.')->atPath('to')->addViolation();
        } elseif ($months > GetBusinessSummaryQuery::MAX_MONTHS) {
            $context->buildViolation('The period must not be longer than {{ limit }} months.')
                ->setParameter('{{ limit }}', (string) GetBusinessSummaryQuery::MAX_MONTHS)
                ->atPath('to')
                ->addViolation();
        }
    }

    /** Months since year 0, for counting the months between two YYYY-MM strings. */
    private static function index(string $month): int
    {
        return (int) substr($month, 0, 4) * 12 + (int) substr($month, 5, 2);
    }
}
