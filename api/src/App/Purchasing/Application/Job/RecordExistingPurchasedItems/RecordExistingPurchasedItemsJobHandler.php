<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\RecordExistingPurchasedItems;

use App\Expenses\Application\Query\ListPurchaseInvoicesWithItems\ListPurchaseInvoicesWithItemsQuery;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithItems\PurchaseInvoiceWithItemsView;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Copies the items of the organization's invoices into purchased items (#240, ADR 0041 §2). The job
 * bus has switched the tenant to the organization.
 *
 * - Goes through the invoices that have items by id, after the job's position, a page at a time, and
 *   sends RecordPurchasedItemsCommand for each, one flush per invoice. Purchasing leaves the items it
 *   has as they are, so a run done twice, or after the items were recorded as invoices were imported,
 *   changes nothing.
 * - After its time budget it queues itself again from the last invoice it handled and stops; every run
 *   handles at least one invoice, so the chain always moves on.
 */
final readonly class RecordExistingPurchasedItemsJobHandler implements JobHandlerInterface
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

    public function __invoke(RecordExistingPurchasedItemsJob $job): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $job->organizationId) {
            throw new UnrecoverableMessageHandlingException('Recording existing purchased items runs only inside its own organization.');
        }

        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS));
        $after = $job->afterInvoiceId;
        $handled = 0;

        do {
            /** @var list<PurchaseInvoiceWithItemsView> $invoices */
            $invoices = $this->queryBus->ask(new ListPurchaseInvoicesWithItemsQuery($job->organizationId, $after, self::PAGE_SIZE));
            foreach ($invoices as $invoice) {
                if ($handled > 0 && $this->clock->now() >= $deadline) {
                    $this->jobBus->dispatch(new RecordExistingPurchasedItemsJob($job->organizationId, $after), RecordExistingPurchasedItemsJob::deduplicationKey($job->organizationId, $after));
                    $this->finished($job, $handled, continued: true);

                    return;
                }

                $this->commandBus->handle(RecordPurchasedItemsCommand::of(
                    $job->organizationId,
                    $invoice->invoiceId,
                    $invoice->issueDate,
                    $invoice->kind,
                    $invoice->currency,
                    $invoice->sellerTaxId,
                    $invoice->sellerName,
                    $invoice->items,
                ));
                ++$handled;
                $after = $invoice->invoiceId;
            }
        } while (self::PAGE_SIZE === \count($invoices));

        $this->finished($job, $handled, continued: false);
    }

    private function finished(RecordExistingPurchasedItemsJob $job, int $invoices, bool $continued): void
    {
        $this->logger->info('Purchased items recorded for {invoices} invoice(s){continued}.', [
            'organization_id' => $job->organizationId,
            'invoices' => $invoices,
            'continued' => $continued ? '; continues in a new job' : '',
        ]);
    }
}
