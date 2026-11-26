<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\MatchPurchasedItems;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\FinishMatchingRun\FinishMatchingRunCommand;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Command\StartMatchingRun\StartMatchingRunCommand;
use App\Purchasing\Application\Query\ListMatchablePurchasedItemIds\ListMatchablePurchasedItemIdsQuery;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Model\MatchingRunOutcome;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Goes through the organization's matchable items by id, a page at a time, one MatchPurchasedItemsCommand
 * per page (one flush each). The job bus has switched the tenant to the organization.
 *
 * - Safe to interrupt and to run twice: a page's items are noted as matched, or left as they were.
 * - After its time budget it queues itself again from the last item it handled and stops.
 * - When the model cannot answer (down, rate limited, rejected credentials), that is recorded, not
 *   thrown (ADR 0025 §6): the job ends, and the items it did not reach stay for the next run, which
 *   the next recorded invoice, confirmation or `app:purchasing:match-items` queues.
 * - A job that uses the model, with one configured, records its run once it knows it has items (MatchingRun, ADR
 *   0046 §6): started with the first page, ended with `done` or `model_unavailable`; a continuation keeps the run.
 * - With `$askedBefore` it goes only through the items the model has not been through since then (ADR 0046 §3).
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
        private ItemInterpreterInterface $interpreter,
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
        $runId = $job->runId;
        $recording = $job->useInterpreter && $this->interpreter->isAvailable();

        do {
            /** @var list<string> $ids */
            $ids = $this->queryBus->ask(new ListMatchablePurchasedItemIdsQuery($job->scope, $after, self::PAGE_SIZE, $job->askedBefore));
            if ([] === $ids) {
                break;
            }
            if (null === $runId && $recording) {
                $runId = Uuid::v7()->toRfc4122();
                $this->commandBus->handle(new StartMatchingRunCommand($job->organizationId, $runId, $job->scope));
            }
            if ($handled > 0 && $this->clock->now() >= $deadline) {
                $this->jobBus->dispatch(
                    new MatchPurchasedItemsJob($job->organizationId, $job->scope, $job->useInterpreter, $after, $job->askedBefore, $runId),
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
                $this->endRun($job->organizationId, $runId, MatchingRunOutcome::ModelUnavailable);
                $this->finished($job, $handled, 'stopped, the model was unavailable');

                return;
            }
            $handled += \count($ids);
            $after = $ids[\count($ids) - 1];
        } while (self::PAGE_SIZE === \count($ids));

        $this->endRun($job->organizationId, $runId, MatchingRunOutcome::Done);
        $this->finished($job, $handled, 'done');
    }

    private function endRun(string $organizationId, ?string $runId, MatchingRunOutcome $outcome): void
    {
        if (null !== $runId) {
            $this->commandBus->handle(new FinishMatchingRunCommand($organizationId, $runId, $outcome->value));
        }
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
