<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\TimeTracking\Application\Command\ClockIn\ClockInCommand;
use App\TimeTracking\Application\Command\ClockIn\ClockInHandler;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ClockInHandler::class)]
final class ClockInHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 16:00:00';

    private InMemoryShiftRepository $shifts;
    private MockClock $clock;
    private StubQueryBus $queryBus;
    private string $organizationId;
    private string $userId;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->clock = new MockClock(self::NOW);
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->userId = Uuid::v7()->toRfc4122();
        $this->queryBus = MembershipQueryBus::of($this->organizationId, [$this->userId]);
    }

    public function test_it_opens_a_shift_for_the_person_at_the_clocks_now(): void
    {
        $shiftId = Uuid::v7()->toRfc4122();

        $this->handle($shiftId);

        $shift = $this->shifts->findById(Uuid::fromString($shiftId));
        self::assertNotNull($shift);
        self::assertSame($this->userId, $shift->getUserId()->toRfc4122());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getStartedAt());
        self::assertTrue($shift->isOpen());
        self::assertNull($shift->getNote());
        self::assertEquals([new GetOrganizationQuery($this->organizationId, $this->userId)], $this->queryBus->queries);
    }

    public function test_it_refuses_a_person_who_is_already_clocked_in(): void
    {
        $this->handle(Uuid::v7()->toRfc4122());

        try {
            $this->handle(Uuid::v7()->toRfc4122());
            self::fail('Expected ShiftAlreadyOpenException.');
        } catch (ShiftAlreadyOpenException) {
        }

        self::assertCount(1, $this->shifts->all());
    }

    public function test_it_refuses_a_concurrent_clock_in_that_the_repository_catches(): void
    {
        $this->handle(Uuid::v7()->toRfc4122());
        $this->shifts->staleReads = true;

        $this->expectException(ShiftAlreadyOpenException::class);

        $this->handle(Uuid::v7()->toRfc4122());
    }

    public function test_it_opens_a_new_shift_after_the_previous_one_ended(): void
    {
        $first = Uuid::v7()->toRfc4122();
        $this->handle($first);
        $this->shifts->findById(Uuid::fromString($first))?->clockOut(new \DateTimeImmutable('2026-11-12 00:30:00'));
        $this->clock->modify('2026-11-12 16:00:00');

        $second = Uuid::v7()->toRfc4122();
        $this->handle($second);

        self::assertEquals(new \DateTimeImmutable('2026-11-12 16:00:00'), $this->shifts->findOpenByUser(Uuid::fromString($this->userId))?->getStartedAt());
        self::assertCount(2, $this->shifts->all());
    }

    public function test_it_refuses_a_person_who_is_not_an_active_member(): void
    {
        $this->userId = Uuid::v7()->toRfc4122();

        try {
            $this->handle(Uuid::v7()->toRfc4122());
            self::fail('Expected OrganizationNotFoundException.');
        } catch (OrganizationNotFoundException) {
        }

        self::assertSame([], $this->shifts->all());
    }

    private function handle(string $shiftId): void
    {
        (new ClockInHandler($this->shifts, $this->queryBus, $this->clock))(
            new ClockInCommand($this->organizationId, $shiftId, $this->userId),
        );
    }
}
