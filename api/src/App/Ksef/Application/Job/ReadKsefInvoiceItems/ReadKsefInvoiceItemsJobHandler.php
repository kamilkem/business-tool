<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ReadKsefInvoiceItems;

use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsCommand;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentQuery;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\PurchaseInvoiceDocumentView;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithoutItems\ListPurchaseInvoicesWithoutItemsQuery;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\UnsupportedInvoiceFormException;
use App\Ksef\Domain\Fa3InvoiceParserInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Reads the items of an organization's KSeF invoices that have their FA(3) XML and no items yet
 * (#230, ADR 0040). The job bus has switched the tenant to the organization. Nothing reaches KSeF:
 * the XML is the copy Expenses keeps (ADR 0024).
 *
 * - Goes through the invoices by id, after the job's position, a page at a time: parses each XML
 *   again and sends RecordPurchaseInvoiceItemsCommand, one flush per invoice. Expenses leaves an
 *   invoice that has items meanwhile as it is, so a run done twice changes nothing.
 * - An XML that lists no items (an advance invoice) sends nothing; one that no longer parses, or whose
 *   items Expenses refuses, is skipped and counted, never thrown (ADR 0025).
 * - After its time budget it queues itself again from the last invoice it handled and stops; every run
 *   handles at least one invoice, so the chain always moves on.
 */
final readonly class ReadKsefInvoiceItemsJobHandler implements JobHandlerInterface
{
    public const int TIME_BUDGET_SECONDS = 10 * 60;
    public const int PAGE_SIZE = 100;

    public function __construct(
        private Fa3InvoiceParserInterface $parser,
        private TenantContextInterface $tenantContext,
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReadKsefInvoiceItemsJob $job): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $job->organizationId) {
            throw new UnrecoverableMessageHandlingException('Reading KSeF invoice items runs only inside its own organization.');
        }

        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS));
        $after = $job->afterInvoiceId;
        $recorded = 0;
        $withoutItems = 0;
        $skipped = 0;
        $handled = 0;

        do {
            /** @var list<string> $invoiceIds */
            $invoiceIds = $this->queryBus->ask(new ListPurchaseInvoicesWithoutItemsQuery($job->organizationId, PurchaseInvoiceSource::Ksef->value, $after, self::PAGE_SIZE));
            foreach ($invoiceIds as $invoiceId) {
                if ($handled > 0 && $this->clock->now() >= $deadline) {
                    $this->jobBus->dispatch(new ReadKsefInvoiceItemsJob($job->organizationId, $after), ReadKsefInvoiceItemsJob::deduplicationKey($job->organizationId, $after));
                    $this->finished($job, $recorded, $withoutItems, $skipped, continued: true);

                    return;
                }

                match ($this->readItems($job->organizationId, $invoiceId)) {
                    'recorded' => ++$recorded,
                    'none' => ++$withoutItems,
                    'skipped' => ++$skipped,
                };
                ++$handled;
                $after = $invoiceId;
            }
        } while (self::PAGE_SIZE === \count($invoiceIds));

        $this->finished($job, $recorded, $withoutItems, $skipped, continued: false);
    }

    /** @return 'recorded'|'none'|'skipped' */
    private function readItems(string $organizationId, string $invoiceId): string
    {
        /** @var PurchaseInvoiceDocumentView|null $document */
        $document = $this->queryBus->ask(GetPurchaseInvoiceDocumentQuery::forImporter($organizationId, $invoiceId));
        if (null === $document) {
            return 'none';
        }

        try {
            $items = $this->parser->parse($document->content)->items;
            if ([] === $items) {
                return 'none';
            }
            $this->commandBus->handle(RecordPurchaseInvoiceItemsCommand::of($organizationId, $invoiceId, $items));

            return 'recorded';
        } catch (UnsupportedInvoiceFormException|InvalidFa3InvoiceException|InvalidPurchaseInvoiceException $e) {
            $this->logger->warning('Items of the KSeF invoice {invoice_id} skipped: {reason}', [
                'organization_id' => $organizationId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);

            return 'skipped';
        }
    }

    private function finished(ReadKsefInvoiceItemsJob $job, int $recorded, int $withoutItems, int $skipped, bool $continued): void
    {
        $this->logger->info('KSeF invoice items read: {recorded} recorded, {without_items} without items, {skipped} skipped{continued}.', [
            'organization_id' => $job->organizationId,
            'recorded' => $recorded,
            'without_items' => $withoutItems,
            'skipped' => $skipped,
            'continued' => $continued ? '; continues in a new job' : '',
        ]);
    }
}
