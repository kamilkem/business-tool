<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\FinishMatchingRun;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\MatchingRunOutcome;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Counts, from the items, what the model did since the run started (ADR 0046 §6) and ends the run with it. A run that
 * is not there (or is ended already) is left alone: the job that asks is never failed by its bookkeeping.
 */
final readonly class FinishMatchingRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private MatchingRunRepositoryInterface $runs,
        private PurchasedItemRepositoryInterface $items,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /** @throws \LogicException when the organization is not the one the job runs in */
    public function __invoke(FinishMatchingRunCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('A matching run is ended only in the organization the job runs in.');
        }

        $run = Uuid::isValid($command->runId) ? $this->runs->findById(Uuid::fromString($command->runId)) : null;
        if (null === $run || null !== $run->getFinishedAt()) {
            return;
        }

        $run->finish(MatchingRunOutcome::from($command->outcome), $this->items->countModelOutcomesSince($run->getStartedAt()), $this->clock->now());
        $this->runs->save($run);
    }
}
