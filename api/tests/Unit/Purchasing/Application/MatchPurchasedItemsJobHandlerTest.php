<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\FinishMatchingRun\FinishMatchingRunCommand;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Command\StartMatchingRun\StartMatchingRunCommand;
use App\Purchasing\Application\EventHandler\QueueMatchingOnProductChanged;
use App\Purchasing\Domain\Event\ProductChanged;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Application\EventHandler\LearnProductAliasesOnPurchasedItemsConfirmed;
use App\Purchasing\Application\EventHandler\QueueAliasMatchingOnPurchasedItemsConfirmed;
use App\Purchasing\Application\EventHandler\QueueMatchingOnProductCreated;
use App\Purchasing\Application\EventHandler\QueueMatchingOnPurchasedItemsRecorded;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJobHandler;
use App\Purchasing\Application\Command\LearnProductAliases\LearnProductAliasesCommand;
use App\Purchasing\Application\Query\ListMatchablePurchasedItemIds\ListMatchablePurchasedItemIdsHandler;
use App\Purchasing\Application\Query\ListMatchablePurchasedItemIds\ListMatchablePurchasedItemIdsQuery;
use App\Purchasing\Domain\Event\ProductCreated;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Event\PurchasedItemsRecorded;
use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\InMemoryItemInterpreter;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/** The matching job pages through the matchable items, and the events that queue it (ADR 0042 §7). */
#[CoversClass(MatchPurchasedItemsJobHandler::class)]
#[CoversClass(MatchPurchasedItemsJob::class)]
#[CoversClass(ListMatchablePurchasedItemIdsHandler::class)]
#[CoversClass(QueueMatchingOnPurchasedItemsRecorded::class)]
#[CoversClass(QueueMatchingOnProductCreated::class)]
#[CoversClass(QueueMatchingOnProductChanged::class)]
#[CoversClass(QueueAliasMatchingOnPurchasedItemsConfirmed::class)]
#[CoversClass(LearnProductAliasesOnPurchasedItemsConfirmed::class)]
final class MatchPurchasedItemsJobHandlerTest extends TestCase
{
    private InMemoryPurchasedItemRepository $items;
    private Organization $organization;
    private InMemoryTenantContext $tenantContext;
    private RecordingJobBus $jobBus;
    private MockClock $clock;
    private ?\Closure $afterCommand = null;
    private ?ItemInterpreterUnavailableException $failure = null;
    private RecordingCommandBus $commandBus;
    private StubQueryBus $queryBus;
    private InMemoryItemInterpreter $interpreter;

    protected function setUp(): void
    {
        $this->items = new InMemoryPurchasedItemRepository();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->jobBus = new RecordingJobBus();
        $this->clock = new MockClock('2026-11-26 03:00:00');
        $list = new ListMatchablePurchasedItemIdsHandler($this->items);
        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => $query instanceof ListMatchablePurchasedItemIdsQuery ? $list($query) : throw new \LogicException('Unexpected query.'));
        $this->interpreter = new InMemoryItemInterpreter();
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command): void {
            if (!$command instanceof MatchPurchasedItemsCommand) {
                return;
            }
            if (null !== $this->failure) {
                throw $this->failure;
            }
            if (null !== $this->afterCommand) {
                ($this->afterCommand)($command);
            }
        });
    }

    public function test_it_matches_the_items_a_page_at_a_time_and_ends(): void
    {
        $this->addItems(MatchPurchasedItemsJobHandler::PAGE_SIZE + 5);

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::NotYetMatched->value, true));

        $commands = $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class);
        self::assertCount(2, $commands);
        self::assertCount(MatchPurchasedItemsJobHandler::PAGE_SIZE, $commands[0]->itemIds);
        self::assertCount(5, $commands[1]->itemIds);
        self::assertTrue($commands[0]->useInterpreter);
        self::assertSame([], $this->jobBus->dispatched);
    }

    public function test_it_leaves_out_what_is_not_in_its_scope(): void
    {
        $matchedBefore = $this->addItems(1)[0];
        $matchedBefore->match(null, null, \App\Purchasing\Domain\Model\PurchasedItemStatus::Unassigned, $this->clock->now());
        $new = $this->addItems(1)[0];

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::NotYetMatched->value, true));
        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true));

        $commands = $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class);
        self::assertSame([$new->getId()->toRfc4122()], $commands[0]->itemIds);
        self::assertEqualsCanonicalizing([$matchedBefore->getId()->toRfc4122(), $new->getId()->toRfc4122()], $commands[1]->itemIds);
    }

    public function test_it_queues_itself_from_the_last_item_after_its_time_budget_and_stops(): void
    {
        $this->addItems(MatchPurchasedItemsJobHandler::PAGE_SIZE * 2);
        $this->afterCommand = fn () => $this->clock->sleep(MatchPurchasedItemsJobHandler::TIME_BUDGET_SECONDS + 1);

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::NotYetMatched->value, true));

        self::assertCount(1, $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class));
        self::assertCount(1, $this->jobBus->dispatched);
        $queued = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $queued);
        $firstPage = $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class)[0]->itemIds;
        self::assertSame($firstPage[\count($firstPage) - 1], $queued->afterItemId);
        self::assertSame(MatchPurchasedItemsJob::deduplicationKey($this->organizationId(), MatchScope::NotYetMatched->value, true, $queued->afterItemId), $this->jobBus->dispatched[0]['deduplicationKey']);
    }

    public function test_it_records_that_the_model_was_unavailable_and_ends_without_throwing(): void
    {
        $this->addItems(3);
        $this->failure = ItemInterpreterUnavailableException::because('the API answered 429 (rate_limit_error)');

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::NotYetMatched->value, true));

        self::assertCount(1, $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class));
        self::assertSame([], $this->jobBus->dispatched);
    }

    public function test_it_refuses_to_run_in_another_organization(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->runJob(new MatchPurchasedItemsJob(Uuid::v7()->toRfc4122(), MatchScope::NotYetMatched->value, true));
    }

    public function test_it_queues_matching_with_the_model_when_items_are_recorded_one_job_per_organization(): void
    {
        $handler = new QueueMatchingOnPurchasedItemsRecorded($this->jobBus);

        $handler(new PurchasedItemsRecorded($this->organizationId(), [Uuid::v7()->toRfc4122()]));

        $job = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame([MatchScope::NotYetMatched->value, true, null], [$job->scope, $job->useInterpreter, $job->afterItemId]);
        self::assertSame('purchasing-match-items:'.$this->organizationId().':not_yet_matched:model', $this->jobBus->dispatched[0]['deduplicationKey']);
    }

    public function test_it_queues_matching_with_the_model_when_a_product_is_created_one_job_for_a_burst(): void
    {
        $handler = new QueueMatchingOnProductCreated($this->jobBus, $this->clock);

        $handler(new ProductCreated($this->organizationId(), Uuid::v7()->toRfc4122()));
        $handler(new ProductCreated($this->organizationId(), Uuid::v7()->toRfc4122()));

        $job = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame([MatchScope::NotYetMatched->value, true, null], [$job->scope, $job->useInterpreter, $job->afterItemId]);
        // The same key as for recorded items: the job bus drops a job whose key is queued, so the burst is one job (ADR 0025 §5).
        self::assertSame('purchasing-match-items:'.$this->organizationId().':not_yet_matched:model', $this->jobBus->dispatched[0]['deduplicationKey']);
        self::assertSame($this->jobBus->dispatched[0]['deduplicationKey'], $this->jobBus->dispatched[2]['deduplicationKey']);
        self::assertSame(0, $this->jobBus->delays[0]);
    }

    public function test_it_queues_one_delayed_pass_over_the_unassigned_items_for_a_burst_of_products_created_or_changed(): void
    {
        $created = new QueueMatchingOnProductCreated($this->jobBus, $this->clock);
        $changed = new QueueMatchingOnProductChanged($this->jobBus, $this->clock);
        $productId = Uuid::v7()->toRfc4122();

        $created(new ProductCreated($this->organizationId(), $productId));
        $this->clock->sleep(20);
        $changed(new ProductChanged($this->organizationId(), $productId, true, false));

        // Per event: the immediate pass over the items never asked, then the pass over the unassigned ones, delayed.
        self::assertSame([0, MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS, 0, MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS], $this->jobBus->delays);
        $settling = [$this->jobBus->dispatched[1], $this->jobBus->dispatched[3]];
        foreach ($settling as $dispatched) {
            self::assertSame('purchasing-match-items:'.$this->organizationId().':after-catalog-change', $dispatched['deduplicationKey'], 'One key: the job bus drops the second while the first waits (ADR 0025 §5).');
            self::assertInstanceOf(MatchPurchasedItemsJob::class, $dispatched['job']);
            self::assertSame([MatchScope::Unassigned->value, true, null], [$dispatched['job']->scope, $dispatched['job']->useInterpreter, $dispatched['job']->afterItemId]);
        }
        self::assertEquals(
            ['2026-11-26T03:00:00.000000+00:00', '2026-11-26T03:00:20.000000+00:00'],
            array_map(static fn (array $dispatched): ?string => $dispatched['job'] instanceof MatchPurchasedItemsJob ? $dispatched['job']->askedBefore : null, $settling),
        );
        // The key of the button's job is not the settling key, so a waiting pass never swallows "Match again".
        self::assertNotSame(MatchPurchasedItemsJob::deduplicationKey($this->organizationId(), MatchScope::Unassigned->value, true), $settling[0]['deduplicationKey']);
    }

    public function test_it_goes_only_through_the_items_the_model_has_not_been_through_since_the_trigger(): void
    {
        [$before, $after, $never] = $this->addItems(3);
        $before->match(null, null, PurchasedItemStatus::Unassigned, new \DateTimeImmutable('2026-11-26 02:00:00'));
        $after->match(null, null, PurchasedItemStatus::Unassigned, new \DateTimeImmutable('2026-11-26 02:30:00.5'));

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true, null, MatchPurchasedItemsJob::cutoffOf(new \DateTimeImmutable('2026-11-26 02:30:00'))));

        $commands = $this->commandBus->commandsOf(MatchPurchasedItemsCommand::class);
        self::assertEqualsCanonicalizing([$before->getId()->toRfc4122(), $never->getId()->toRfc4122()], $commands[0]->itemIds, 'A second pass with the same cutoff finds nothing the first one asked.');
    }

    public function test_it_records_a_run_that_uses_the_model_and_ends_it_when_done(): void
    {
        $this->addItems(MatchPurchasedItemsJobHandler::PAGE_SIZE + 1);

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true));

        $started = $this->commandBus->commandsOf(StartMatchingRunCommand::class);
        $finished = $this->commandBus->commandsOf(FinishMatchingRunCommand::class);
        self::assertCount(1, $started);
        self::assertSame([$this->organizationId(), MatchScope::Unassigned->value], [$started[0]->organizationId, $started[0]->scope]);
        self::assertCount(1, $finished);
        self::assertSame([$started[0]->runId, 'done'], [$finished[0]->runId, $finished[0]->outcome]);
        // Started before the first page is matched, ended after the last.
        self::assertSame([StartMatchingRunCommand::class, MatchPurchasedItemsCommand::class, MatchPurchasedItemsCommand::class, FinishMatchingRunCommand::class], array_map('get_class', $this->commandBus->commands));
    }

    public function test_it_records_no_run_without_items_without_the_model_or_when_asked_for_aliases_only(): void
    {
        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true));
        $this->addItems(2);
        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::AllMatchable->value, false));
        $this->interpreter->available = false;
        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true));

        self::assertSame([], $this->commandBus->commandsOf(StartMatchingRunCommand::class));
        self::assertSame([], $this->commandBus->commandsOf(FinishMatchingRunCommand::class));
    }

    public function test_it_ends_the_run_as_model_unavailable_when_the_model_cannot_answer(): void
    {
        $this->addItems(3);
        $this->failure = ItemInterpreterUnavailableException::because('the API answered 429 (rate_limit_error)');

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true));

        $finished = $this->commandBus->commandsOf(FinishMatchingRunCommand::class);
        self::assertCount(1, $finished);
        self::assertSame('model_unavailable', $finished[0]->outcome);
    }

    public function test_a_continuation_keeps_the_run_and_the_cutoff_and_does_not_start_another_run(): void
    {
        $this->addItems(MatchPurchasedItemsJobHandler::PAGE_SIZE * 2);
        $this->afterCommand = fn () => $this->clock->sleep(MatchPurchasedItemsJobHandler::TIME_BUDGET_SECONDS + 1);
        $cutoff = MatchPurchasedItemsJob::cutoffOf($this->clock->now());

        $this->runJob(new MatchPurchasedItemsJob($this->organizationId(), MatchScope::Unassigned->value, true, null, $cutoff));

        $runId = $this->commandBus->commandsOf(StartMatchingRunCommand::class)[0]->runId;
        $queued = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $queued);
        self::assertSame([$cutoff, $runId], [$queued->askedBefore, $queued->runId]);
        self::assertSame([], $this->commandBus->commandsOf(FinishMatchingRunCommand::class), 'The run goes on in the next job.');

        $this->afterCommand = null;
        $this->runJob($queued);

        self::assertCount(1, $this->commandBus->commandsOf(StartMatchingRunCommand::class));
        self::assertSame([$runId], array_map(static fn (FinishMatchingRunCommand $command): string => $command->runId, $this->commandBus->commandsOf(FinishMatchingRunCommand::class)));
    }

    public function test_it_learns_aliases_and_queues_matching_by_aliases_alone_when_a_person_confirms(): void
    {
        $itemId = Uuid::v7()->toRfc4122();
        $event = new PurchasedItemsConfirmed($this->organizationId(), [$itemId]);

        (new LearnProductAliasesOnPurchasedItemsConfirmed($this->commandBus))($event);
        (new QueueAliasMatchingOnPurchasedItemsConfirmed($this->jobBus))($event);

        $learn = $this->commandBus->commandsOf(LearnProductAliasesCommand::class);
        self::assertSame([$itemId], $learn[0]->itemIds);
        $job = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame([MatchScope::AllMatchable->value, false], [$job->scope, $job->useInterpreter]);
        self::assertSame('purchasing-match-items:'.$this->organizationId().':all_matchable:aliases', $this->jobBus->dispatched[0]['deduplicationKey']);
    }

    private function runJob(MatchPurchasedItemsJob $job): void
    {
        (new MatchPurchasedItemsJobHandler($this->tenantContext, $this->queryBus, $this->commandBus, $this->jobBus, $this->interpreter, $this->clock, new NullLogger()))($job);
    }

    /** @return list<PurchasedItem> */
    private function addItems(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
                Uuid::v7(), 1, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                1, 'Produkt '.$i, null, null, 'szt.', '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
            ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        }
        $this->items->addAll($items);

        return $items;
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }
}
