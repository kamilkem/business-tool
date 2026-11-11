<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\TimeTracking\Application\Command\ClockOut\ClockOutCommand;
use App\TimeTracking\Application\Command\ClockOut\ClockOutHandler;
use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Model\Shift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ClockOutHandler::class)]
final class ClockOutHandlerTest extends TestCase
{
    private const string START = '2026-11-11 16:00:00';
    private const string NOW = '2026-11-12 00:30:00';

    private InMemoryShiftRepository $shifts;
    private MockClock $clock;
    private string $organizationId;
    private string $userId;
    private string $colleagueId;
    private Shift $shift;

    /** @var list<string> */
    private array $activeMemberIds;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->clock = new MockClock(self::NOW);
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->userId = Uuid::v7()->toRfc4122();
        $this->colleagueId = Uuid::v7()->toRfc4122();
        $this->activeMemberIds = [$this->userId, $this->colleagueId];
        $this->shift = Shift::clockIn(Uuid::v7(), Uuid::fromString($this->userId), new \DateTimeImmutable(self::START), null);
        $this->shifts->add($this->shift);
    }

    public function test_it_ends_the_persons_shift_at_the_clocks_now_and_saves_it(): void
    {
        $this->handle($this->shift->getId()->toRfc4122());

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->shift->getEndedAt());
        self::assertFalse($this->shift->isOpen());
        self::assertSame(1, $this->shifts->saveCallCount);
    }

    public function test_it_does_not_let_a_person_clock_out_of_someone_elses_shift(): void
    {
        $this->expectRefusal(ShiftNotFoundException::class, $this->shift->getId()->toRfc4122(), $this->colleagueId);

        self::assertTrue($this->shift->isOpen());
    }

    public function test_it_refuses_an_unknown_or_malformed_shift_id(): void
    {
        $this->expectRefusal(ShiftNotFoundException::class, Uuid::v7()->toRfc4122());
        $this->expectRefusal(ShiftNotFoundException::class, 'not-a-uuid');
    }

    public function test_it_refuses_a_shift_that_has_already_ended(): void
    {
        $this->handle($this->shift->getId()->toRfc4122());
        $this->clock->modify('+1 minute');

        $this->expectRefusal(ShiftAlreadyClosedException::class, $this->shift->getId()->toRfc4122());

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->shift->getEndedAt());
    }

    public function test_it_refuses_to_end_a_shift_in_the_moment_it_started(): void
    {
        $this->clock->modify(self::START);

        $this->expectRefusal(ShiftEndNotAfterStartException::class, $this->shift->getId()->toRfc4122());
    }

    public function test_it_refuses_a_person_who_is_not_an_active_member(): void
    {
        $this->activeMemberIds = [$this->colleagueId]; // deactivated after clocking in

        $this->expectRefusal(OrganizationNotFoundException::class, $this->shift->getId()->toRfc4122());

        self::assertTrue($this->shift->isOpen());
    }

    /** @param class-string<\Throwable> $exception */
    private function expectRefusal(string $exception, string $shiftId, ?string $userId = null): void
    {
        $saveCallCount = $this->shifts->saveCallCount;
        try {
            $this->handle($shiftId, $userId);
            self::fail(\sprintf('Expected %s.', $exception));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }

        self::assertSame($saveCallCount, $this->shifts->saveCallCount);
    }

    private function handle(string $shiftId, ?string $userId = null): void
    {
        $queryBus = MembershipQueryBus::of($this->organizationId, $this->activeMemberIds);
        $command = new ClockOutCommand($this->organizationId, $shiftId, $userId ?? $this->userId);

        (new ClockOutHandler($this->shifts, $queryBus, $this->clock))($command);
    }
}
