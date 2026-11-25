<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsCommand;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsHandler;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentHandler;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentQuery;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithoutItems\ListPurchaseInvoicesWithoutItemsHandler;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithoutItems\ListPurchaseInvoicesWithoutItemsQuery;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Ksef\Application\Job\ReadKsefInvoiceItems\ReadKsefInvoiceItemsJob;
use App\Ksef\Application\Job\ReadKsefInvoiceItems\ReadKsefInvoiceItemsJobHandler;
use App\Ksef\Infrastructure\Fa3\Fa3InvoiceParser;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Expenses\Application\InMemoryPurchaseInvoiceRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * A Polish organization whose KSeF invoices were imported with their FA(3) XML but without items
 * (before #229). The parser is the real FA(3) parser; the query and command buses pass on to the real
 * Expenses handlers over an in-memory repository, so the items can be checked after each run. The job
 * bus only records what the handler queues. The job bus has switched the tenant (InMemoryTenantContext).
 */
#[CoversClass(ReadKsefInvoiceItemsJobHandler::class)]
#[CoversClass(ReadKsefInvoiceItemsJob::class)]
#[CoversClass(ListPurchaseInvoicesWithoutItemsHandler::class)]
#[CoversClass(GetPurchaseInvoiceDocumentHandler::class)]
#[CoversClass(RecordPurchaseInvoiceItemsHandler::class)]
final class ReadKsefInvoiceItemsJobHandlerTest extends TestCase
{
    private const string NOW = '2026-11-25 05:00:00';

    private MockClock $clock;
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private RecordingCommandBus $commandBus;
    private RecordingJobBus $jobBus;
    private RecordingLogger $logger;
    private Organization $organization;
    private string $xml;
    /** @var (\Closure(CommandInterface): void)|null runs after each command reached its handler */
    private ?\Closure $afterCommand = null;
    private ?StubQueryBus $queryBus = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->xml = self::fixture('Fa3/vat-several-rates.xml');
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->invoices = new InMemoryPurchaseInvoiceRepository();

        $recordItems = new RecordPurchaseInvoiceItemsHandler($this->invoices, $this->tenantContext, new RecordingEventPublisher());
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command) use ($recordItems): void {
            $command instanceof RecordPurchaseInvoiceItemsCommand ? $recordItems($command) : throw new \LogicException(\sprintf('Unexpected command %s.', $command::class));
            if (null !== $this->afterCommand) {
                ($this->afterCommand)($command);
            }
        });
        $this->jobBus = new RecordingJobBus();
        $this->logger = new RecordingLogger();
    }

    public function test_it_records_the_items_each_stored_xml_lists(): void
    {
        $first = $this->importWithDocument($this->xml);
        $second = $this->importWithDocument($this->xml);

        $this->runJob();

        self::assertSame(self::summary(new Fa3InvoiceParser()->parse($this->xml)->items), self::summary($first->getItems()));
        self::assertSame(self::summary($first->getItems()), self::summary($second->getItems()));
        self::assertSame([1, 'Piwo jasne, keg 30 l', 100_000, '23'], self::summary($first->getItems())[0]);
        self::assertCount(5, $first->getItems());
        self::assertCount(2, $this->commandBus->commandsOf(RecordPurchaseInvoiceItemsCommand::class));
        self::assertSame(2, $this->invoices->saveCallCount, 'One flush per invoice.');
        self::assertSame([], $this->jobBus->dispatched);
        self::assertSame(['recorded' => 2, 'without_items' => 0, 'skipped' => 0], $this->counts());
    }

    public function test_it_leaves_alone_an_invoice_that_already_has_items(): void
    {
        $withItems = $this->importWithDocument($this->xml, [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]);
        $without = $this->importWithDocument($this->xml);

        $this->runJob();

        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')], $withItems->getItems());
        self::assertCount(5, $without->getItems());
        self::assertSame([$without->getId()->toRfc4122()], array_map(static fn (RecordPurchaseInvoiceItemsCommand $command): string => $command->invoiceId, $this->commandBus->commandsOf(RecordPurchaseInvoiceItemsCommand::class)));
    }

    public function test_it_changes_nothing_when_run_again(): void
    {
        $invoice = $this->importWithDocument($this->xml);
        $this->runJob();
        $items = $invoice->getItems();

        $this->runJob();

        self::assertEquals($items, $invoice->getItems());
        self::assertCount(1, $this->commandBus->commandsOf(RecordPurchaseInvoiceItemsCommand::class));
    }

    public function test_it_passes_over_manual_invoices_invoices_without_a_document_and_advance_invoices(): void
    {
        $this->invoices->add(PurchaseInvoiceMother::manual());
        $this->invoices->add(PurchaseInvoiceMother::imported());
        $advance = $this->importWithDocument(self::fixture('Fa3/advance.xml'));

        $this->runJob();

        self::assertSame([], $advance->getItems());
        self::assertSame([], $this->commandBus->commands);
        self::assertSame(['recorded' => 0, 'without_items' => 1, 'skipped' => 0], $this->counts());
    }

    public function test_it_skips_and_counts_an_xml_that_no_longer_parses_and_goes_on(): void
    {
        $broken = $this->importWithDocument('<Faktura><P_2>FV/1');
        $notFa3 = $this->importWithDocument('<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
        $readable = $this->importWithDocument($this->xml);

        $this->runJob();

        self::assertSame([], $broken->getItems());
        self::assertSame([], $notFa3->getItems());
        self::assertCount(5, $readable->getItems());
        self::assertSame(['recorded' => 1, 'without_items' => 0, 'skipped' => 2], $this->counts());
        self::assertCount(2, $this->logger->messages('warning'));
        self::assertStringContainsString($broken->getId()->toRfc4122(), $this->logger->dump());
    }

    public function test_it_skips_and_counts_items_expenses_refuses(): void
    {
        // The FA(3) parser reads a name of any length; Expenses keeps at most 512 characters.
        $refused = $this->importWithDocument(str_replace('<P_7>Piwo jasne, keg 30 l</P_7>', '<P_7>'.str_repeat('Piwo ', 120).'</P_7>', $this->xml, $count));
        self::assertSame(1, $count);

        $this->runJob();

        self::assertSame([], $refused->getItems(), 'None of its items, not only the refused one.');
        self::assertSame(['recorded' => 0, 'without_items' => 0, 'skipped' => 1], $this->counts());
        self::assertStringContainsString('item name', $this->logger->dump());
    }

    public function test_it_reads_past_one_page(): void
    {
        $invoices = [];
        for ($i = 0; $i <= ReadKsefInvoiceItemsJobHandler::PAGE_SIZE; ++$i) {
            $invoices[] = $this->importWithDocument($this->xml);
        }

        $this->runJob();

        self::assertSame([], array_filter($invoices, static fn (PurchaseInvoice $invoice): bool => [] === $invoice->getItems()));
        self::assertSame(ReadKsefInvoiceItemsJobHandler::PAGE_SIZE + 1, $this->counts()['recorded']);
    }

    public function test_it_queues_itself_with_its_cursor_when_the_budget_runs_out_and_the_next_job_continues(): void
    {
        $invoices = [];
        for ($i = 0; $i < 4; ++$i) {
            $invoices[] = $this->importWithDocument($this->xml);
        }
        $ids = $this->idsInOrder($invoices);
        // Each invoice takes 4 minutes: the budget of 10 minutes ends after the third.
        $this->afterCommand = function (): void {
            $this->clock->sleep(4 * 60);
        };

        $this->runJob();

        $organizationId = $this->organization->getId()->toRfc4122();
        self::assertEquals([[
            'job' => new ReadKsefInvoiceItemsJob($organizationId, $ids[2]),
            'deduplicationKey' => 'ksef-read-invoice-items:'.$organizationId.':'.$ids[2],
        ]], $this->jobBus->dispatched);
        self::assertSame(array_slice($ids, 0, 3), $this->recordedIds());
        self::assertSame(['recorded' => 3, 'without_items' => 0, 'skipped' => 0], $this->counts());

        $this->afterCommand = null;
        $next = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(ReadKsefInvoiceItemsJob::class, $next);
        $this->handler()($next);

        self::assertSame($ids, $this->recordedIds());
        self::assertCount(1, $this->jobBus->dispatched, 'Done: nothing more is queued.');
    }

    public function test_it_carries_the_cursor_past_invoices_that_got_no_items(): void
    {
        $advance = $this->importWithDocument(self::fixture('Fa3/advance.xml'));
        $broken = $this->importWithDocument('<Faktura>');
        $readable = $this->importWithDocument($this->xml);
        $ids = $this->idsInOrder([$advance, $broken, $readable]);

        $this->handler()(new ReadKsefInvoiceItemsJob($this->organization->getId()->toRfc4122(), $ids[1]));

        self::assertSame(1, array_sum($this->counts()), 'Only the invoice after the cursor is read.');
        self::assertSame([$ids[2]], $this->queriedDocuments(), 'Only the document of the invoice after the cursor.');
    }

    public function test_it_handles_at_least_one_invoice_per_job(): void
    {
        $invoices = [$this->importWithDocument($this->xml), $this->importWithDocument($this->xml)];
        $this->afterCommand = function (): void {
            $this->clock->sleep(ReadKsefInvoiceItemsJobHandler::TIME_BUDGET_SECONDS * 2);
        };

        $this->runJob();

        $ids = $this->idsInOrder($invoices);
        self::assertSame([$ids[0]], $this->recordedIds());
        $next = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(ReadKsefInvoiceItemsJob::class, $next);
        self::assertSame($ids[0], $next->afterInvoiceId);
    }

    public function test_it_refuses_a_job_for_another_organization_than_the_tenant(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new ReadKsefInvoiceItemsJob(Uuid::v7()->toRfc4122()));
    }

    public function test_the_document_query_answers_an_importer_only_inside_its_organization(): void
    {
        $invoice = $this->importWithDocument($this->xml);
        $handler = new GetPurchaseInvoiceDocumentHandler($this->invoices, new StubQueryBus(static fn (): never => throw new \LogicException('An importer has no actor to resolve.')), $this->tenantContext);

        self::assertSame($this->xml, $handler(GetPurchaseInvoiceDocumentQuery::forImporter($this->organization->getId()->toRfc4122(), $invoice->getId()->toRfc4122()))?->content);
        self::assertNull($handler(GetPurchaseInvoiceDocumentQuery::forImporter(Uuid::v7()->toRfc4122(), $invoice->getId()->toRfc4122())));
        $this->tenantContext->reset();
        self::assertNull($handler(GetPurchaseInvoiceDocumentQuery::forImporter($this->organization->getId()->toRfc4122(), $invoice->getId()->toRfc4122())));
    }

    public function test_the_list_query_pages_by_id_and_lists_nothing_for_a_manual_source_or_another_organization(): void
    {
        $ids = $this->idsInOrder([$this->importWithDocument($this->xml), $this->importWithDocument($this->xml), $this->importWithDocument($this->xml)]);
        $handler = new ListPurchaseInvoicesWithoutItemsHandler($this->invoices, $this->tenantContext);
        $organizationId = $this->organization->getId()->toRfc4122();

        self::assertSame(array_slice($ids, 0, 2), $handler(new ListPurchaseInvoicesWithoutItemsQuery($organizationId, 'ksef', null, 2)));
        self::assertSame([$ids[2]], $handler(new ListPurchaseInvoicesWithoutItemsQuery($organizationId, 'ksef', $ids[1], 2)));
        self::assertSame([], $handler(new ListPurchaseInvoicesWithoutItemsQuery($organizationId, 'manual', null, 2)));
        self::assertSame([], $handler(new ListPurchaseInvoicesWithoutItemsQuery($organizationId, 'unknown', null, 2)));
        self::assertSame([], $handler(new ListPurchaseInvoicesWithoutItemsQuery(Uuid::v7()->toRfc4122(), 'ksef', null, 2)));
    }

    /** @param list<PurchaseInvoiceItemDetails> $items */
    private function importWithDocument(string $content, array $items = []): PurchaseInvoice
    {
        $invoice = PurchaseInvoiceMother::imported(items: $items);
        $this->invoices->add($invoice, PurchaseInvoiceDocument::of($invoice, $content, 'FA (3)', new \DateTimeImmutable(PurchaseInvoiceMother::IMPORTED_AT)));

        return $invoice;
    }

    private function runJob(): void
    {
        $this->handler()(new ReadKsefInvoiceItemsJob($this->organization->getId()->toRfc4122()));
    }

    private function handler(): ReadKsefInvoiceItemsJobHandler
    {
        $queryBus = null;
        $listWithoutItems = new ListPurchaseInvoicesWithoutItemsHandler($this->invoices, $this->tenantContext);
        $queryBus = new StubQueryBus(function (QueryInterface $query) use (&$queryBus, $listWithoutItems): mixed {
            \assert($queryBus instanceof StubQueryBus);

            return match (true) {
                $query instanceof ListPurchaseInvoicesWithoutItemsQuery => $listWithoutItems($query),
                $query instanceof GetPurchaseInvoiceDocumentQuery => new GetPurchaseInvoiceDocumentHandler($this->invoices, $queryBus, $this->tenantContext)($query),
                default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
            };
        });

        $this->queryBus = $queryBus;

        return new ReadKsefInvoiceItemsJobHandler(new Fa3InvoiceParser(), $this->tenantContext, $queryBus, $this->commandBus, $this->jobBus, $this->clock, $this->logger);
    }

    /** @return array{recorded: mixed, without_items: mixed, skipped: mixed} what the last run logged */
    private function counts(): array
    {
        $finished = array_values(array_filter($this->logger->records, static fn (array $record): bool => str_starts_with($record['message'], 'KSeF invoice items read')));
        $context = $finished[array_key_last($finished) ?? self::fail('The run logged nothing.')]['context'];

        return ['recorded' => $context['recorded'], 'without_items' => $context['without_items'], 'skipped' => $context['skipped']];
    }

    /** @return list<string> the ids of the invoices whose document the last handler asked for */
    private function queriedDocuments(): array
    {
        $queries = array_filter($this->queryBus->queries ?? [], static fn (QueryInterface $query): bool => $query instanceof GetPurchaseInvoiceDocumentQuery);

        return array_values(array_map(static fn (GetPurchaseInvoiceDocumentQuery $query): string => $query->invoiceId, $queries));
    }

    /** @return list<string> the ids of the invoices that got items, in the order the job sent them */
    private function recordedIds(): array
    {
        return array_map(static fn (RecordPurchaseInvoiceItemsCommand $command): string => $command->invoiceId, $this->commandBus->commandsOf(RecordPurchaseInvoiceItemsCommand::class));
    }

    /**
     * @param list<PurchaseInvoice> $invoices
     *
     * @return list<string> their ids in the order the job reads them
     */
    private function idsInOrder(array $invoices): array
    {
        $ids = array_map(static fn (PurchaseInvoice $invoice): string => $invoice->getId()->toRfc4122(), $invoices);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<PurchaseInvoiceItemDetails> $items
     *
     * @return list<array{int, string, ?int, ?string}> position, name, net amount, VAT rate: what Expenses keeps as the parser gives it
     */
    private static function summary(array $items): array
    {
        return array_map(static fn (PurchaseInvoiceItemDetails $item): array => [$item->position, $item->name, $item->netAmount, $item->vatRate], $items);
    }

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Ksef/'.$path);
    }
}
