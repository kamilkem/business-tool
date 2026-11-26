<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\BackfillProductAliases;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\BackfillProductAliases\BackfillProductAliasesCommand;
use App\Purchasing\Application\Query\ListConfirmedPurchasedItemIds\ListConfirmedPurchasedItemIdsQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Goes through the organization's confirmed items, newest invoice first, a page at a time, one
 * BackfillProductAliasesCommand per page (one flush each). The job bus has switched the tenant.
 *
 * - Idempotent and safe to interrupt: a command only creates the aliases that are missing, so a page done
 *   twice, or a run done after another, changes nothing more. Newest first, so that of two items with
 *   the same key the newer one taught it, as the last confirmation of a key wins (ADR 0042 §3).
 * - After its time budget it queues itself again from the last item it handled and stops.
 * - It never changes an item or a link, and never calls the model.
 */
final readonly class BackfillProductAliasesJobHandler implements JobHandlerInterface
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

    public function __invoke(BackfillProductAliasesJob $job): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $job->organizationId) {
            throw new UnrecoverableMessageHandlingException('Backfilling aliases runs only inside its own organization.');
        }

        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS));
        $after = $job->afterItemId;
        $handled = 0;

        do {
            /** @var list<string> $ids */
            $ids = $this->queryBus->ask(new ListConfirmedPurchasedItemIdsQuery($after, self::PAGE_SIZE));
            if ([] === $ids) {
                break;
            }
            if ($handled > 0 && $this->clock->now() >= $deadline) {
                $this->jobBus->dispatch(new BackfillProductAliasesJob($job->organizationId, $after), BackfillProductAliasesJob::deduplicationKey($job->organizationId, $after));
                $this->finished($job, $handled, 'continues in a new job');

                return;
            }

            $this->commandBus->handle(new BackfillProductAliasesCommand($job->organizationId, $ids));
            $handled += \count($ids);
            $after = $ids[\count($ids) - 1];
        } while (self::PAGE_SIZE === \count($ids));

        $this->finished($job, $handled, 'done');
    }

    private function finished(BackfillProductAliasesJob $job, int $items, string $outcome): void
    {
        $this->logger->info('Backfilling aliases: {items} confirmed item(s) went through, {outcome}.', [
            'organization_id' => $job->organizationId,
            'items' => $items,
            'outcome' => $outcome,
        ]);
    }
}
