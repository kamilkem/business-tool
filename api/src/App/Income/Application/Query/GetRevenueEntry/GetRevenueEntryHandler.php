<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetRevenueEntryHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private RevenueMonthRepositoryInterface $months,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws IncomeManagementForbiddenException when the actor is an employee */
    public function __invoke(GetRevenueEntryQuery $query): ?RevenueEntryView
    {
        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $entry = Uuid::isValid($query->entryId) ? $this->entries->findById(Uuid::fromString($query->entryId)) : null;

        return null === $entry ? null : RevenueEntryView::fromEntry($entry, $this->months->locks());
    }
}
