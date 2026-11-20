<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Validator;

use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The cross-field rules of a `?from=&to=` period of business dates, for the list inputs' callbacks (Income, Expenses). */
final class BusinessPeriodRules
{
    public static function validate(ExecutionContextInterface $context, ?string $from, ?string $to, int $maxDays): void
    {
        if (null === $from || null === $to) {
            return;
        }

        try {
            $period = BusinessPeriod::fromStrings($from, $to);
        } catch (InvalidBusinessDateException) {
            return; // Assert\Date reports it.
        } catch (InvalidBusinessPeriodException) {
            $context->buildViolation('The last date must not be before the first date.')->atPath('to')->addViolation();

            return;
        }

        if ($period->dayCount() > $maxDays) {
            $context->buildViolation('The period must not be longer than {{ limit }} days.')
                ->setParameter('{{ limit }}', (string) $maxDays)
                ->atPath('to')
                ->addViolation();
        }
    }
}
