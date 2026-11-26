<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetMatchingStatus;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Psr\Clock\ClockInterface;

final readonly class GetMatchingStatusHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private MatchingRunRepositoryInterface $runs,
        private ItemInterpreterInterface $interpreter,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return MatchingStatusView|null null when the actor is not an active member of the organization
     *
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(GetMatchingStatusQuery $query): ?MatchingStatusView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $run = $this->runs->findLatest();

        return new MatchingStatusView(
            $this->items->countMatchable(MatchScope::Unassigned),
            $this->interpreter->isAvailable(),
            null === $run ? null : MatchingRunView::fromRun($run, $this->clock->now()),
        );
    }
}
