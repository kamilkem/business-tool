<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\TimeTracking\Application\Query\GetCurrentShift\GetCurrentShiftHandler;
use App\TimeTracking\Application\Query\GetCurrentShift\GetCurrentShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Model\Shift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetCurrentShiftHandler::class)]
#[CoversClass(ShiftView::class)]
final class GetCurrentShiftHandlerTest extends TestCase
{
    private InMemoryShiftRepository $shifts;
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->userId = Uuid::v7();
    }

    public function test_it_returns_the_persons_open_shift(): void
    {
        $ended = Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable('2026-11-10 16:00:00'), null);
        $ended->clockOut(new \DateTimeImmutable('2026-11-11 00:30:00'));
        $this->shifts->add($ended);
        $open = Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable('2026-11-11 16:00:00'), null, 'Opening');
        $this->shifts->add($open);

        self::assertEquals(new ShiftView(
            $open->getId()->toRfc4122(),
            $this->userId->toRfc4122(),
            new \DateTimeImmutable('2026-11-11 16:00:00'),
            null,
            'Opening',
        ), $this->ask($this->userId->toRfc4122()));
    }

    public function test_it_returns_null_when_the_person_is_not_clocked_in(): void
    {
        $this->shifts->add(Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-11-11 16:00:00'), null));

        self::assertNull($this->ask($this->userId->toRfc4122()));
        self::assertNull($this->ask('not-a-uuid'));
    }

    private function ask(string $userId): ?ShiftView
    {
        return (new GetCurrentShiftHandler($this->shifts))(new GetCurrentShiftQuery($userId));
    }
}
