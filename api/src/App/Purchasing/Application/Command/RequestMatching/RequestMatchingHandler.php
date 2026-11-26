<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\RequestMatching;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;

/**
 * Queues the pass over every unassigned item with the model, now (ADR 0046 §4): the job `app:purchasing:match-items
 * --again` queues, under its key (so a second click while one is queued or running adds nothing), limited to what the
 * model has not been through since this moment. It changes no aggregate: the run shows up in the status when the job
 * starts.
 */
final readonly class RequestMatchingHandler implements CommandHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     */
    public function __invoke(RequestMatchingCommand $command): void
    {
        PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $this->jobBus->dispatch(
            new MatchPurchasedItemsJob($command->organizationId, MatchScope::Unassigned->value, true, null, MatchPurchasedItemsJob::cutoffOf($this->clock->now())),
            MatchPurchasedItemsJob::deduplicationKey($command->organizationId, MatchScope::Unassigned->value, true),
        );
    }
}
