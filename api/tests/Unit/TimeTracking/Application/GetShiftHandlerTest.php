<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\TimeTracking\Application\Query\GetShift\GetShiftHandler;
use App\TimeTracking\Application\Query\GetShift\GetShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Model\Shift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetShiftHandler::class)]
final class GetShiftHandlerTest extends TestCase
{
    private InMemoryShiftRepository $shifts;
    private Shift $shift;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->shift = Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-11-11 16:00:00'), null);
        $this->shift->clockOut(new \DateTimeImmutable('2026-11-12 00:30:00'));
        $this->shifts->add($this->shift);
    }

    public function test_it_returns_the_persons_own_shift(): void
    {
        $view = $this->ask($this->shift->getId()->toRfc4122(), $this->shift->getUserId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame($this->shift->getId()->toRfc4122(), $view->id);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 00:30:00'), $view->endedAt);
    }

    public function test_it_hides_someone_elses_shift(): void
    {
        self::assertNull($this->ask($this->shift->getId()->toRfc4122(), Uuid::v7()->toRfc4122()));
    }

    public function test_it_returns_null_for_an_unknown_or_malformed_id(): void
    {
        $userId = $this->shift->getUserId()->toRfc4122();

        self::assertNull($this->ask(Uuid::v7()->toRfc4122(), $userId));
        self::assertNull($this->ask('not-a-uuid', $userId));
        self::assertNull($this->ask($this->shift->getId()->toRfc4122(), 'not-a-uuid'));
    }

    private function ask(string $shiftId, string $userId): ?ShiftView
    {
        return (new GetShiftHandler($this->shifts))(new GetShiftQuery($shiftId, $userId));
    }
}
