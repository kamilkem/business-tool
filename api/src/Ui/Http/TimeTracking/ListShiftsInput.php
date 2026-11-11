<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\TimeTracking\Application\Query\ListShifts\ListShiftsQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The query string of GET /shifts. ShiftCollectionProvider builds it from the query parameters and
 * validates it, so a bad date or period is a 422 with violations like any other Input.
 */
final readonly class ListShiftsInput
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
        /** Only this person's shifts. */
        #[Assert\Uuid]
        public ?string $userId = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if (null === $this->from || null === $this->to) {
            return;
        }

        try {
            $period = BusinessPeriod::fromStrings($this->from, $this->to);
        } catch (InvalidBusinessDateException) {
            return; // Assert\Date reports it.
        } catch (InvalidBusinessPeriodException) {
            $context->buildViolation('The last date must not be before the first date.')->atPath('to')->addViolation();

            return;
        }

        if ($period->dayCount() > ListShiftsQuery::MAX_DAYS) {
            $context->buildViolation('The period must not be longer than {{ limit }} days.')
                ->setParameter('{{ limit }}', (string) ListShiftsQuery::MAX_DAYS)
                ->atPath('to')
                ->addViolation();
        }
    }
}
