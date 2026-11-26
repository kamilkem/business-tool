<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\MatchPurchasedItems;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Query\ListMatchablePurchasedItemIds\ListMatchablePurchasedItemIdsQuery;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Goes through the organization's matchable items by id, a page at a time, one MatchPurchasedItemsCommand
 * per page (one flush each). The job bus has switched the tenant to the organization.
 *
 * - Safe to interrupt and to run twice: a page's items are noted as matched, or left as they were.
 * - After its time budget it queues itself again from the last item it handled and stops.
 * - When the model cannot answer (down, rate limited, rejected credentials), that is recorded, not
 *   thrown (ADR 0025 §6): the job ends, and the items it did not reach stay for the next run, which
 *   the next recorded invoice, confirmation or `app:purchasing:match-items` queues.
 */
final readonly class MatchPurchasedItemsJobHandler implements JobHandlerInterface
{
    public const int TIME_BUDGET_SECONDS = 10 * 60;
    public const int PAGE_SIZE = 100;

    public function __construct(
        private TenantContextInterface $tenantContext,
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MatchPurchasedItemsJob $job): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $job->organizationId) {
            throw new UnrecoverableMessageHandlingException('Matching purchased items runs only inside its own organization.');
        }

        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS));
        $after = $job->afterItemId;
        $handled = 0;

        do {
            /** @var list<string> $ids */
            $ids = $this->queryBus->ask(new ListMatchablePurchasedItemIdsQuery($job->scope, $after, self::PAGE_SIZE));
            if ([] === $ids) {
                break;
            }
            if ($handled > 0 && $this->clock->now() >= $deadline) {
                $this->jobBus->dispatch(
                    new MatchPurchasedItemsJob($job->organizationId, $job->scope, $job->useInterpreter, $after),
                    MatchPurchasedItemsJob::deduplicationKey($job->organizationId, $job->scope, $job->useInterpreter, $after),
                );
                $this->finished($job, $handled, 'continues in a new job');

                return;
            }

            try {
                $this->commandBus->handle(new MatchPurchasedItemsCommand($job->organizationId, $ids, $job->useInterpreter));
            } catch (ItemInterpreterUnavailableException $e) {
                $this->logger->warning('Matching purchased items stopped, the model could not answer: {reason}', [
                    'organization_id' => $job->organizationId,
                    'reason' => $e->getMessage(),
                ]);
                $this->finished($job, $handled, 'stopped, the model was unavailable');

                return;
            }
            $handled += \count($ids);
            $after = $ids[\count($ids) - 1];
        } while (self::PAGE_SIZE === \count($ids));

        $this->finished($job, $handled, 'done');
    }

    private function finished(MatchPurchasedItemsJob $job, int $items, string $outcome): void
    {
        $this->logger->info('Matching purchased items ({scope}): {items} item(s) went through, {outcome}.', [
            'organization_id' => $job->organizationId,
            'scope' => $job->scope,
            'items' => $items,
            'outcome' => $outcome,
        ]);
    }
}
