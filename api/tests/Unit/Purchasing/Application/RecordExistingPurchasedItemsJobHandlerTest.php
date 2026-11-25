<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Expenses\Application\Query\ListPurchaseInvoicesWithItems\ListPurchaseInvoicesWithItemsHandler;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithItems\ListPurchaseInvoicesWithItemsQuery;
use App\Expenses\Application\Query\ListPurchaseInvoicesWithItems\PurchaseInvoiceWithItemsView;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsHandler;
use App\Purchasing\Application\Job\RecordExistingPurchasedItems\RecordExistingPurchasedItemsJob;
use App\Purchasing\Application\Job\RecordExistingPurchasedItems\RecordExistingPurchasedItemsJobHandler;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Expenses\Application\InMemoryPurchaseInvoiceRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordExistingPurchasedItemsJobHandler::class)]
#[CoversClass(RecordExistingPurchasedItemsJob::class)]
#[CoversClass(ListPurchaseInvoicesWithItemsHandler::class)]
#[CoversClass(PurchaseInvoiceWithItemsView::class)]
final class RecordExistingPurchasedItemsJobHandlerTest extends TestCase
{
    private const string NOW = '2026-11-25 03:00:00';

    private MockClock $clock;
    private Organization $organization;
    private InMemoryTenantContext $tenantContext;
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryPurchasedItemRepository $items;
    private RecordingCommandBus $commandBus;
    private RecordingJobBus $jobBus;
    private RecordingLogger $logger;
    /** @var (\Closure(CommandInterface): void)|null runs after each command reached its handler */
    private ?\Closure $afterCommand = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->items = new InMemoryPurchasedItemRepository();

        $record = new RecordPurchasedItemsHandler($this->items, $this->tenantContext, $this->clock);
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command) use ($record): void {
            $command instanceof RecordPurchasedItemsCommand ? $record($command) : throw new \LogicException(\sprintf('Unexpected command %s.', $command::class));
            if (null !== $this->afterCommand) {
                ($this->afterCommand)($command);
            }
        });
        $this->jobBus = new RecordingJobBus();
        $this->logger = new RecordingLogger();
    }

    public function test_it_copies_the_items_of_every_invoice_that_has_them(): void
    {
        $first = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-10-01', items: [self::item('Piwo'), self::item('Wino')]));
        $second = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-11-02', kind: PurchaseInvoiceKind::Correction, currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('23', 20_000, 4_600, 20_000)], items: [self::item('Woda', beforeCorrection: true)]));
        $this->store(PurchaseInvoiceMother::imported());
        $this->store(PurchaseInvoiceMother::manual());

        $this->runJob();

        self::assertCount(2, $this->commandBus->commandsOf(RecordPurchasedItemsCommand::class), 'One command per invoice with items.');
        self::assertSame(['Piwo', 'Wino'], array_map(static fn ($item): string => $item->getName(), $this->items->findByInvoice($first->getId())));
        $copied = $this->items->findByInvoice($second->getId());
        self::assertCount(1, $copied);
        self::assertSame('2026-11-02', $copied[0]->getInvoiceIssueDate()->toString());
        self::assertSame('correction', $copied[0]->getInvoiceKind());
        self::assertSame('EUR', $copied[0]->getCurrency());
        self::assertSame('Hurtownia Napojów', $copied[0]->getSellerName());
        self::assertTrue($copied[0]->isBeforeCorrection());
        self::assertCount(3, $this->items->all());
        self::assertSame([], $this->jobBus->dispatched);
        self::assertSame(['invoices' => 2, 'continued' => ''], $this->logged());
    }

    public function test_it_changes_nothing_when_run_again(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo'), self::item('Wino')]));
        $this->runJob();
        $before = $this->items->findByInvoice($invoice->getId());

        $this->runJob();

        self::assertSame($before, $this->items->findByInvoice($invoice->getId()));
        self::assertSame(1, $this->items->addAllCallCount, 'The second run found everything recorded and wrote nothing.');
        self::assertCount(2, $this->items->all());
    }

    public function test_it_adds_what_is_missing_next_to_items_already_recorded(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo'), self::item('Wino')]));
        $this->runJob();
        $first = $this->items->findByInvoice($invoice->getId())[0];
        $this->store(PurchaseInvoiceMother::imported(items: [self::item('Woda')]));

        $this->runJob();

        self::assertCount(3, $this->items->all());
        self::assertSame($first, $this->items->findByInvoice($invoice->getId())[0]);
    }

    public function test_it_goes_past_one_page(): void
    {
        for ($i = 0; $i <= RecordExistingPurchasedItemsJobHandler::PAGE_SIZE; ++$i) {
            $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo')]));
        }

        $this->runJob();

        self::assertCount(RecordExistingPurchasedItemsJobHandler::PAGE_SIZE + 1, $this->items->all());
        self::assertSame(RecordExistingPurchasedItemsJobHandler::PAGE_SIZE + 1, $this->logged()['invoices']);
        self::assertSame([], $this->jobBus->dispatched);
    }

    public function test_it_queues_itself_with_its_cursor_when_the_budget_runs_out_and_the_next_job_continues(): void
    {
        $invoices = [];
        for ($i = 0; $i < 4; ++$i) {
            $invoices[] = $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo')]));
        }
        $ids = $this->idsInOrder($invoices);
        // Each invoice takes 4 minutes: the budget of 10 minutes ends after the third.
        $this->afterCommand = function (): void {
            $this->clock->sleep(4 * 60);
        };

        $this->runJob();

        $organizationId = $this->organization->getId()->toRfc4122();
        self::assertEquals([[
            'job' => new RecordExistingPurchasedItemsJob($organizationId, $ids[2]),
            'deduplicationKey' => 'purchasing-record-existing-items:'.$organizationId.':'.$ids[2],
        ]], $this->jobBus->dispatched);
        self::assertSame(array_slice($ids, 0, 3), $this->recordedInvoiceIds());
        self::assertSame(['invoices' => 3, 'continued' => '; continues in a new job'], $this->logged());

        $this->afterCommand = null;
        $next = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(RecordExistingPurchasedItemsJob::class, $next);
        $this->handler()($next);

        self::assertSame($ids, array_map(static fn ($item): string => $item->getInvoiceId()->toRfc4122(), $this->sortedByInvoice()));
        self::assertCount(1, $this->jobBus->dispatched, 'Done: nothing more is queued.');
    }

    public function test_it_handles_at_least_one_invoice_per_job(): void
    {
        $invoices = [$this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo')])), $this->store(PurchaseInvoiceMother::imported(items: [self::item('Wino')]))];
        $this->afterCommand = function (): void {
            $this->clock->sleep(RecordExistingPurchasedItemsJobHandler::TIME_BUDGET_SECONDS * 2);
        };

        $this->runJob();

        $ids = $this->idsInOrder($invoices);
        self::assertSame([$ids[0]], $this->recordedInvoiceIds());
        $next = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(RecordExistingPurchasedItemsJob::class, $next);
        self::assertSame($ids[0], $next->afterInvoiceId);
    }

    public function test_it_starts_after_the_cursor_it_is_given(): void
    {
        $invoices = [];
        for ($i = 0; $i < 3; ++$i) {
            $invoices[] = $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo')]));
        }
        $ids = $this->idsInOrder($invoices);

        $this->handler()(new RecordExistingPurchasedItemsJob($this->organization->getId()->toRfc4122(), $ids[0]));

        self::assertSame([$ids[1], $ids[2]], $this->recordedInvoiceIds());
    }

    public function test_it_refuses_a_job_for_another_organization_than_the_tenant(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new RecordExistingPurchasedItemsJob(Uuid::v7()->toRfc4122()));
    }

    public function test_the_deduplication_key_names_the_organization_and_the_position(): void
    {
        self::assertSame('purchasing-record-existing-items:org', RecordExistingPurchasedItemsJob::deduplicationKey('org'));
        self::assertSame('purchasing-record-existing-items:org:inv', RecordExistingPurchasedItemsJob::deduplicationKey('org', 'inv'));
    }

    public function test_the_list_query_pages_by_id_and_lists_nothing_for_another_organization_or_no_tenant(): void
    {
        $invoices = [];
        for ($i = 0; $i < 3; ++$i) {
            $invoices[] = $this->store(PurchaseInvoiceMother::imported(items: [self::item('Piwo'), self::item('Wino')]));
        }
        $this->store(PurchaseInvoiceMother::imported());
        $ids = $this->idsInOrder($invoices);
        $handler = new ListPurchaseInvoicesWithItemsHandler($this->invoices, $this->tenantContext);
        $organizationId = $this->organization->getId()->toRfc4122();

        self::assertSame(\array_slice($ids, 0, 2), array_map(static fn (PurchaseInvoiceWithItemsView $view): string => $view->invoiceId, $handler(new ListPurchaseInvoicesWithItemsQuery($organizationId, null, 2))));
        $page = $handler(new ListPurchaseInvoicesWithItemsQuery($organizationId, $ids[1], 2));
        self::assertSame([$ids[2]], array_map(static fn (PurchaseInvoiceWithItemsView $view): string => $view->invoiceId, $page));
        self::assertSame([1, 2], array_column($page[0]->items, 'index'));
        self::assertSame(['Piwo', 'Wino'], array_column($page[0]->items, 'name'));
        self::assertSame([], $handler(new ListPurchaseInvoicesWithItemsQuery(Uuid::v7()->toRfc4122(), null, 2)));
        $this->tenantContext->reset();
        self::assertSame([], $handler(new ListPurchaseInvoicesWithItemsQuery($organizationId, null, 2)));
    }

    public function test_the_list_query_refuses_a_cursor_that_is_not_an_invoice_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ListPurchaseInvoicesWithItemsHandler($this->invoices, $this->tenantContext))(new ListPurchaseInvoicesWithItemsQuery($this->organization->getId()->toRfc4122(), 'nope', 10));
    }

    private function store(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->invoices->add($invoice);

        return $invoice;
    }

    private static function item(string $name, bool $beforeCorrection = false): PurchaseInvoiceItemDetails
    {
        return new PurchaseInvoiceItemDetails(position: 1, name: $name, unit: 'szt.', quantity: '2', netAmount: 1_000, beforeCorrection: $beforeCorrection);
    }

    private function runJob(): void
    {
        $this->handler()(new RecordExistingPurchasedItemsJob($this->organization->getId()->toRfc4122()));
    }

    private function handler(): RecordExistingPurchasedItemsJobHandler
    {
        $list = new ListPurchaseInvoicesWithItemsHandler($this->invoices, $this->tenantContext);
        $queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => $query instanceof ListPurchaseInvoicesWithItemsQuery ? $list($query) : throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)));

        return new RecordExistingPurchasedItemsJobHandler($this->tenantContext, $queryBus, $this->commandBus, $this->jobBus, $this->clock, $this->logger);
    }

    /** @return array{invoices: mixed, continued: mixed} what the last run logged */
    private function logged(): array
    {
        $finished = array_values(array_filter($this->logger->records, static fn (array $record): bool => str_starts_with($record['message'], 'Purchased items recorded')));
        $context = $finished[array_key_last($finished) ?? self::fail('The run logged nothing.')]['context'];

        return ['invoices' => $context['invoices'], 'continued' => $context['continued']];
    }

    /** @return list<string> the ids of the invoices the job sent, in order */
    private function recordedInvoiceIds(): array
    {
        return array_map(static fn (RecordPurchasedItemsCommand $command): string => $command->invoiceId, $this->commandBus->commandsOf(RecordPurchasedItemsCommand::class));
    }

    /** @return list<\App\Purchasing\Domain\Model\PurchasedItem> by invoice id */
    private function sortedByInvoice(): array
    {
        $items = $this->items->all();
        usort($items, static fn ($a, $b): int => strcmp($a->getInvoiceId()->toRfc4122(), $b->getInvoiceId()->toRfc4122()));

        return $items;
    }

    /**
     * @param list<PurchaseInvoice> $invoices
     *
     * @return list<string> their ids in the order the job pages them: by id
     */
    private function idsInOrder(array $invoices): array
    {
        $ids = array_map(static fn (PurchaseInvoice $invoice): string => $invoice->getId()->toRfc4122(), $invoices);
        sort($ids);

        return $ids;
    }
}
