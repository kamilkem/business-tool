<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRevenueEntryChanges;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenuePeriodTooLongException;
use App\Income\Domain\Repository\RevenueEntryChangeRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class ListRevenueEntryChangesHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueEntryChangeRepositoryInterface $changes,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<RevenueEntryChangeView>|null newest first
     *
     * @throws TimeException                      when $from or $to is not a date, or $to is before $from
     * @throws RevenuePeriodTooLongException      when the period is longer than MAX_DAYS
     * @throws IncomeManagementForbiddenException when the actor is an employee
     * @throws \LogicException                    when neither an entry nor a period is given (the Ui input requires one)
     */
    public function __invoke(ListRevenueEntryChangesQuery $query): ?array
    {
        $period = null;
        if (null !== $query->from && null !== $query->to) {
            $period = BusinessPeriod::fromStrings($query->from, $query->to);
            if ($period->dayCount() > ListRevenueEntryChangesQuery::MAX_DAYS) {
                throw RevenuePeriodTooLongException::of($period, ListRevenueEntryChangesQuery::MAX_DAYS);
            }
        }

        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        if (null !== $query->entryId) {
            $changes = Uuid::isValid($query->entryId) ? $this->changes->findByEntryId(Uuid::fromString($query->entryId)) : [];
            if (null !== $period) {
                $changes = array_values(array_filter($changes, static fn ($change): bool => $period->contains($change->getBusinessDate())));
            }
        } elseif (null !== $period) {
            $changes = $this->changes->findInPeriod($period);
        } else {
            throw new \LogicException('An entry or a period is needed.');
        }

        return array_map(RevenueEntryChangeView::of(...), $changes);
    }
}
