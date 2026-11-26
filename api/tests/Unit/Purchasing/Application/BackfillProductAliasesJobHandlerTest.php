<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\BackfillProductAliases\BackfillProductAliasesCommand;
use App\Purchasing\Application\Command\BackfillProductAliases\BackfillProductAliasesHandler;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJob;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJobHandler;
use App\Purchasing\Application\Query\ListConfirmedPurchasedItemIds\ListConfirmedPurchasedItemIdsHandler;
use App\Purchasing\Application\Query\ListConfirmedPurchasedItemIds\ListConfirmedPurchasedItemIdsQuery;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
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

/** The backfill job pages through the confirmed items newest first, once, and is safe to interrupt and repeat (ADR 0045 §3). */
#[CoversClass(BackfillProductAliasesJobHandler::class)]
#[CoversClass(BackfillProductAliasesJob::class)]
#[CoversClass(ListConfirmedPurchasedItemIdsHandler::class)]
final class BackfillProductAliasesJobHandlerTest extends TestCase
{
    private MockClock $clock;
    private Organization $organization;
    private InMemoryTenantContext $tenantContext;
    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductAliasRepository $aliases;
    private RecordingCommandBus $commandBus;
    private RecordingJobBus $jobBus;
    private RecordingLogger $logger;
    private Product $jack;
    /** @var (\Closure(): void)|null runs after each page's command */
    private ?\Closure $afterCommand = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-26 03:00:00');
        $owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($owner);
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->items = new InMemoryPurchasedItemRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));

        $backfill = new BackfillProductAliasesHandler($this->items, $this->aliases, $this->tenantContext, $this->clock, new RecordingLogger());
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command) use ($backfill): void {
            $command instanceof BackfillProductAliasesCommand ? $backfill($command) : throw new \LogicException(\sprintf('Unexpected command %s.', $command::class));
            if (null !== $this->afterCommand) {
                ($this->afterCommand)();
            }
        });
        $this->jobBus = new RecordingJobBus();
        $this->logger = new RecordingLogger();
    }

    public function test_it_learns_the_aliases_of_every_confirmed_item_and_changes_no_item(): void
    {
        $confirmed = $this->confirmed('JACK DANIELS 0,7L', '2026-10-01');
        $open = $this->item('Piwo jasne', '2026-10-02');

        $this->runJob();

        self::assertCount(2, $this->aliases->all(), 'seller_name and name of the confirmed item; the open one teaches nothing.');
        self::assertCount(1, $this->commandBus->commandsOf(BackfillProductAliasesCommand::class));
        self::assertSame([$confirmed->getId()->toRfc4122()], $this->commandBus->commandsOf(BackfillProductAliasesCommand::class)[0]->itemIds);
        self::assertSame(0, $this->items->saveCallCount);
        self::assertNull($open->getProduct());
        self::assertSame([], $this->jobBus->dispatched);
        self::assertSame(1, $this->logged()['items']);
    }

    public function test_it_gives_a_key_to_the_newest_item_that_has_it(): void
    {
        $honey = Product::create(Uuid::v7(), 'Jack Daniel\'s Honey', null, ProductMeasure::Volume, null, false, null, UserMother::any('olivia@example.com')->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->confirmed('JACK DANIELS 0,7L', '2026-09-01', $this->jack);
        $this->confirmed('JACK DANIELS 0,7L', '2026-10-01', $honey);

        $this->runJob();

        foreach ($this->aliases->all() as $alias) {
            self::assertSame($honey, $alias->getProduct());
        }
    }

    public function test_it_changes_nothing_when_run_again(): void
    {
        $this->confirmed('JACK DANIELS 0,7L', '2026-10-01', sellerCode: 'JD-07');
        $this->runJob();
        $before = $this->aliases->all();
        $saved = $this->aliases->saveAllCallCount;

        $this->runJob();

        self::assertSame($before, $this->aliases->all());
        self::assertSame($saved, $this->aliases->saveAllCallCount, 'The second run found every alias and wrote nothing.');
    }

    public function test_it_goes_past_one_page(): void
    {
        for ($i = 0; $i <= BackfillProductAliasesJobHandler::PAGE_SIZE; ++$i) {
            $this->confirmed('Piwo '.$i, '2026-10-01');
        }

        $this->runJob();

        self::assertCount(2, $this->commandBus->commandsOf(BackfillProductAliasesCommand::class));
        self::assertSame(BackfillProductAliasesJobHandler::PAGE_SIZE + 1, $this->logged()['items']);
        self::assertCount(2 * (BackfillProductAliasesJobHandler::PAGE_SIZE + 1), $this->aliases->all());
        self::assertSame([], $this->jobBus->dispatched);
    }

    public function test_it_queues_itself_with_its_cursor_when_the_budget_runs_out_and_the_next_job_continues(): void
    {
        for ($i = 0; $i < 2 * BackfillProductAliasesJobHandler::PAGE_SIZE + 1; ++$i) {
            $this->confirmed('Piwo '.$i, '2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', \STR_PAD_LEFT));
        }
        $this->afterCommand = function (): void {
            $this->clock->sleep(BackfillProductAliasesJobHandler::TIME_BUDGET_SECONDS);
        };

        $this->runJob();

        $organizationId = $this->organization->getId()->toRfc4122();
        $handled = $this->commandBus->commandsOf(BackfillProductAliasesCommand::class);
        self::assertCount(1, $handled, 'One page, then the budget was over.');
        $cursor = $handled[0]->itemIds[BackfillProductAliasesJobHandler::PAGE_SIZE - 1];
        self::assertEquals([[
            'job' => new BackfillProductAliasesJob($organizationId, $cursor),
            'deduplicationKey' => 'purchasing-backfill-aliases:'.$organizationId.':'.$cursor,
        ]], $this->jobBus->dispatched);

        $this->afterCommand = null;
        $next = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(BackfillProductAliasesJob::class, $next);
        $this->handler()($next);

        self::assertCount(3, $this->commandBus->commandsOf(BackfillProductAliasesCommand::class));
        self::assertCount(2 * (2 * BackfillProductAliasesJobHandler::PAGE_SIZE + 1), $this->aliases->all(), 'Every item was passed exactly once across the two jobs.');
        self::assertCount(1, $this->jobBus->dispatched, 'Done: nothing more is queued.');
    }

    public function test_it_lists_the_confirmed_items_newest_first_after_a_cursor(): void
    {
        $oldest = $this->confirmed('A', '2026-08-01');
        $newest = $this->confirmed('B', '2026-10-01');
        $middle = $this->confirmed('C', '2026-09-01');
        $this->item('D', '2026-11-01');
        $list = new ListConfirmedPurchasedItemIdsHandler($this->items);
        $ids = static fn (PurchasedItem ...$items): array => array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items);

        self::assertSame($ids($newest, $middle, $oldest), $list(new ListConfirmedPurchasedItemIdsQuery(null, 10)));
        self::assertSame($ids($newest, $middle), $list(new ListConfirmedPurchasedItemIdsQuery(null, 2)));
        self::assertSame($ids($oldest), $list(new ListConfirmedPurchasedItemIdsQuery($middle->getId()->toRfc4122(), 10)));
        self::assertSame($ids($newest, $middle, $oldest), $list(new ListConfirmedPurchasedItemIdsQuery('not-an-id', 10)));
    }

    public function test_it_refuses_a_job_for_another_organization_than_the_tenant(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new BackfillProductAliasesJob(Uuid::v7()->toRfc4122()));
    }

    public function test_the_deduplication_key_names_the_organization_and_the_position(): void
    {
        self::assertSame('purchasing-backfill-aliases:org', BackfillProductAliasesJob::deduplicationKey('org'));
        self::assertSame('purchasing-backfill-aliases:org:item', BackfillProductAliasesJob::deduplicationKey('org', 'item'));
    }

    private function runJob(): void
    {
        $this->handler()(new BackfillProductAliasesJob($this->organization->getId()->toRfc4122()));
    }

    private function handler(): BackfillProductAliasesJobHandler
    {
        $list = new ListConfirmedPurchasedItemIdsHandler($this->items);
        $queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => $query instanceof ListConfirmedPurchasedItemIdsQuery ? $list($query) : throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)));

        return new BackfillProductAliasesJobHandler($this->tenantContext, $queryBus, $this->commandBus, $this->jobBus, $this->clock, $this->logger);
    }

    /** @return array{items: mixed} what the last run logged */
    private function logged(): array
    {
        $finished = array_values(array_filter($this->logger->records, static fn (array $record): bool => str_starts_with($record['message'], 'Backfilling aliases')));

        return ['items' => $finished[array_key_last($finished) ?? self::fail('The run logged nothing.')]['context']['items']];
    }

    private function confirmed(string $name, string $issueDate, ?Product $product = null, ?string $sellerCode = null): PurchasedItem
    {
        $item = $this->item($name, $issueDate, $sellerCode);
        $item->link($product ?? $this->jack, '0.7', Uuid::v7(), MembershipRole::Owner);

        return $item;
    }

    private function item(string $name, string $issueDate, ?string $sellerCode = null): PurchasedItem
    {
        static $index = 0;
        ++$index;
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString($issueDate), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            $index, $name, $sellerCode, null, 'szt.', '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $this->items->addAll([$item]);

        return $item;
    }
}
