<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\FinishMatchingRun\FinishMatchingRunCommand;
use App\Purchasing\Application\Command\FinishMatchingRun\FinishMatchingRunHandler;
use App\Purchasing\Application\Command\RequestMatching\RequestMatchingCommand;
use App\Purchasing\Application\Command\RequestMatching\RequestMatchingHandler;
use App\Purchasing\Application\Command\StartMatchingRun\StartMatchingRunCommand;
use App\Purchasing\Application\Command\StartMatchingRun\StartMatchingRunHandler;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Application\Query\GetMatchingStatus\GetMatchingStatusHandler;
use App\Purchasing\Application\Query\GetMatchingStatus\GetMatchingStatusQuery;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingRunState;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingRunView;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Model\MatchingRunOutcome;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\InMemoryItemInterpreter;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The record of matching runs, the status the admin reads and "Match again" (ADR 0046). */
#[CoversClass(StartMatchingRunHandler::class)]
#[CoversClass(FinishMatchingRunHandler::class)]
#[CoversClass(RequestMatchingHandler::class)]
#[CoversClass(GetMatchingStatusHandler::class)]
#[CoversClass(MatchingRun::class)]
#[CoversClass(MatchingRunView::class)]
final class MatchingRunHandlersTest extends TestCase
{
    private const string NOW = '2026-11-26 12:00:00';

    private InMemoryMatchingRunRepository $runs;
    private InMemoryPurchasedItemRepository $items;
    private InMemoryTenantContext $tenantContext;
    private InMemoryItemInterpreter $interpreter;
    private RecordingJobBus $jobBus;
    private StubQueryBus $queryBus;
    private MockClock $clock;
    private Organization $organization;
    private User $owner;
    private User $employee;
    private Product $jack;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->runs = new InMemoryMatchingRunRepository();
        $this->items = new InMemoryPurchasedItemRepository();
        $this->interpreter = new InMemoryItemInterpreter();
        $this->jobBus = new RecordingJobBus();
        $this->clock = new MockClock(self::NOW);
        $this->owner = UserMother::any('olivia@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-11-01 08:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->jack = Product::create(Uuid::v7(), 'Jack Daniel\'s', null, ProductMeasure::Volume, null, false, null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->queryBus = new StubQueryBus(static fn ($query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    public function test_it_starts_a_run_and_ends_it_with_what_the_model_did_since_it_started(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->start($runId, MatchScope::Unassigned);
        $items = $this->addItems(6);
        $this->clock->sleep(60);
        $now = $this->clock->now();
        $items[0]->match($this->jack, '0.7', PurchasedItemStatus::Assigned, $now, MatchSource::Model);
        $items[1]->match($this->jack, '0.7', PurchasedItemStatus::Assigned, $now, MatchSource::Model);
        $items[2]->match($this->jack, null, PurchasedItemStatus::Suggested, $now, MatchSource::Model);
        $items[3]->match(null, null, PurchasedItemStatus::Unassigned, $now);
        $items[4]->match($this->jack, '0.7', PurchasedItemStatus::Assigned, $now, MatchSource::Name);
        // items[5]: the model never reached it.
        $this->clock->sleep(30);

        $this->finish($runId, MatchingRunOutcome::Done);

        $run = $this->runs->findById(Uuid::fromString($runId));
        self::assertNotNull($run);
        self::assertSame([MatchScope::Unassigned, MatchingRunOutcome::Done], [$run->getScope(), $run->getOutcome()]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $run->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 12:01:30'), $run->getFinishedAt());
        $counts = $run->getCounts();
        // An alias decided one item (items[4]): the model did not go through it.
        self::assertSame([4, 2, 1, 1], [$counts->interpreted, $counts->assigned, $counts->suggested, $counts->foundNothing()]);
    }

    public function test_it_does_not_count_items_matched_before_the_run_or_taken_off_their_product(): void
    {
        $items = $this->addItems(2);
        $items[0]->match($this->jack, '0.7', PurchasedItemStatus::Assigned, new \DateTimeImmutable('2026-11-26 11:59:59'), MatchSource::Model);
        $runId = Uuid::v7()->toRfc4122();
        $this->start($runId, MatchScope::NotYetMatched);
        $items[1]->link(null, null, $this->owner->getId(), MembershipRole::Owner);

        $this->finish($runId, MatchingRunOutcome::ModelUnavailable);

        $run = $this->runs->findById(Uuid::fromString($runId));
        self::assertNotNull($run);
        self::assertSame(MatchingRunOutcome::ModelUnavailable, $run->getOutcome());
        self::assertSame(0, $run->getCounts()->interpreted);
    }

    public function test_it_ends_a_run_once_and_ignores_one_it_does_not_have(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->start($runId, MatchScope::Unassigned);
        $this->finish($runId, MatchingRunOutcome::ModelUnavailable);
        $this->clock->sleep(5);
        $this->finish($runId, MatchingRunOutcome::Done);
        $this->finish(Uuid::v7()->toRfc4122(), MatchingRunOutcome::Done);
        $this->finish('not-a-uuid', MatchingRunOutcome::Done);

        $run = $this->runs->findById(Uuid::fromString($runId));
        self::assertNotNull($run);
        self::assertSame(MatchingRunOutcome::ModelUnavailable, $run->getOutcome());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $run->getFinishedAt());
    }

    public function test_it_refuses_to_record_a_run_in_another_organization(): void
    {
        $this->expectException(\LogicException::class);

        (new StartMatchingRunHandler($this->runs, $this->tenantContext, $this->clock))(new StartMatchingRunCommand(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), MatchScope::Unassigned->value));
    }

    public function test_it_queues_match_again_now_for_the_unassigned_items_under_the_key_of_the_console_command(): void
    {
        $this->request($this->owner);

        $job = $this->jobBus->dispatched[0]['job'];
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame([$this->organizationId(), MatchScope::Unassigned->value, true, null, '2026-11-26T12:00:00.000000+00:00', null], [$job->organizationId, $job->scope, $job->useInterpreter, $job->afterItemId, $job->askedBefore, $job->runId]);
        self::assertSame('purchasing-match-items:'.$this->organizationId().':unassigned:model', $this->jobBus->dispatched[0]['deduplicationKey']);
        self::assertSame([0], $this->jobBus->delays, 'Now, not after the settling delay.');
    }

    public function test_it_refuses_match_again_to_an_employee_and_to_a_stranger_and_queues_nothing(): void
    {
        foreach ([[$this->employee, PurchasingManagementForbiddenException::class], [UserMother::any('stranger@example.com'), OrganizationNotFoundException::class]] as [$actor, $exception]) {
            try {
                $this->request($actor);
                self::fail('Expected '.$exception);
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e);
            }
        }

        self::assertSame([], $this->jobBus->dispatched);
    }

    public function test_it_reports_the_waiting_items_whether_the_model_is_on_and_no_run_before_the_first(): void
    {
        $items = $this->addItems(3);
        $items[0]->match($this->jack, '0.7', PurchasedItemStatus::Assigned, $this->clock->now(), MatchSource::Model);
        $items[1]->link($this->jack, '0.7', $this->owner->getId(), MembershipRole::Owner);

        $status = $this->statusOf($this->owner);

        self::assertNotNull($status);
        self::assertSame([1, true, null], [$status->waitingCount, $status->modelEnabled, $status->lastRun]);

        $this->interpreter->available = false;
        $off = $this->statusOf($this->owner);
        self::assertNotNull($off);
        self::assertFalse($off->modelEnabled);
    }

    public function test_it_reports_the_newest_run_and_its_state(): void
    {
        $older = Uuid::v7()->toRfc4122();
        $this->start($older, MatchScope::NotYetMatched);
        $this->finish($older, MatchingRunOutcome::Done);
        $this->clock->sleep(120);
        $newer = Uuid::v7()->toRfc4122();
        $this->start($newer, MatchScope::Unassigned);

        self::assertSame([$newer, MatchingRunState::Running, null], $this->lastRunOf(fn ($run) => [$run->id, $run->state, $run->finishedAt]));

        $this->clock->sleep(MatchingRunView::RUNNING_FOR_AT_MOST_SECONDS);
        self::assertSame(MatchingRunState::Interrupted, $this->lastRunOf(fn ($run) => $run->state));

        $this->finish($newer, MatchingRunOutcome::ModelUnavailable);
        self::assertSame(MatchingRunState::ModelUnavailable, $this->lastRunOf(fn ($run) => $run->state));
    }

    public function test_it_hides_the_status_from_employees_and_strangers(): void
    {
        try {
            $this->statusOf($this->employee);
            self::fail('An employee may not read it.');
        } catch (PurchasingManagementForbiddenException) {
        }

        self::assertNull($this->statusOf(UserMother::any('stranger@example.com')));
    }

    private function start(string $runId, MatchScope $scope): void
    {
        (new StartMatchingRunHandler($this->runs, $this->tenantContext, $this->clock))(new StartMatchingRunCommand($this->organizationId(), $runId, $scope->value));
    }

    private function finish(string $runId, MatchingRunOutcome $outcome): void
    {
        (new FinishMatchingRunHandler($this->runs, $this->items, $this->tenantContext, $this->clock))(new FinishMatchingRunCommand($this->organizationId(), $runId, $outcome->value));
    }

    private function request(User $actor): void
    {
        (new RequestMatchingHandler($this->queryBus, $this->jobBus, $this->clock))(new RequestMatchingCommand($this->organizationId(), $actor->getId()->toRfc4122()));
    }

    private function statusOf(User $actor): ?\App\Purchasing\Application\Query\GetMatchingStatus\MatchingStatusView
    {
        return (new GetMatchingStatusHandler($this->items, $this->runs, $this->interpreter, $this->queryBus, $this->clock))(new GetMatchingStatusQuery($this->organizationId(), $actor->getId()->toRfc4122()));
    }

    /** @param \Closure(MatchingRunView): mixed $read */
    private function lastRunOf(\Closure $read): mixed
    {
        $lastRun = $this->statusOf($this->owner)?->lastRun;
        self::assertNotNull($lastRun);

        return $read($lastRun);
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
