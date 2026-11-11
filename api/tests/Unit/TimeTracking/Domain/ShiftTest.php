<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Model\Shift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Shift::class)]
final class ShiftTest extends TestCase
{
    private const string START = '2026-11-11 16:00:00';
    private const string END = '2026-11-12 00:30:00';

    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
    }

    public function test_it_clocks_in_at_the_given_moment(): void
    {
        $id = Uuid::v7();
        $start = new \DateTimeImmutable(self::START);

        $shift = Shift::clockIn($id, $this->userId, $start, null, 'Covering for Eve');

        self::assertSame($id, $shift->getId());
        self::assertSame($this->userId, $shift->getUserId());
        self::assertSame($start, $shift->getStartedAt());
        self::assertNull($shift->getEndedAt());
        self::assertSame('Covering for Eve', $shift->getNote());
        self::assertTrue($shift->isOpen());
    }

    public function test_it_clocks_in_without_a_note(): void
    {
        $shift = $this->openShift();

        self::assertNull($shift->getNote());
    }

    public function test_it_refuses_to_clock_in_while_the_person_has_an_open_shift(): void
    {
        $open = $this->openShift();

        $this->expectException(ShiftAlreadyOpenException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::END), $open);
    }

    public function test_it_refuses_an_open_shift_of_another_person_as_a_programming_error(): void
    {
        $someoneElses = Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable(self::START), null);

        $this->expectException(\LogicException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::END), $someoneElses);
    }

    public function test_it_refuses_a_closed_shift_as_the_open_one_as_a_programming_error(): void
    {
        $closed = $this->openShift();
        $closed->clockOut(new \DateTimeImmutable(self::END));

        $this->expectException(\LogicException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable('2026-11-12 16:00:00'), $closed);
    }

    public function test_it_clocks_out_at_the_given_moment(): void
    {
        $shift = $this->openShift();
        $end = new \DateTimeImmutable(self::END);

        $shift->clockOut($end);

        self::assertSame($end, $shift->getEndedAt());
        self::assertEquals(new \DateTimeImmutable(self::START), $shift->getStartedAt());
        self::assertFalse($shift->isOpen());
    }

    public function test_it_clocks_out_one_second_after_the_start(): void
    {
        $shift = $this->openShift();

        $shift->clockOut(new \DateTimeImmutable('2026-11-11 16:00:01'));

        self::assertEquals(new \DateTimeImmutable('2026-11-11 16:00:01'), $shift->getEndedAt());
    }

    public function test_it_refuses_to_end_at_the_moment_it_started(): void
    {
        $shift = $this->openShift();

        $this->expectException(ShiftEndNotAfterStartException::class);

        $shift->clockOut(new \DateTimeImmutable(self::START));
    }

    public function test_it_refuses_to_end_before_it_started(): void
    {
        $shift = $this->openShift();

        try {
            $shift->clockOut(new \DateTimeImmutable('2026-11-11 15:59:59'));
            self::fail('Expected ShiftEndNotAfterStartException.');
        } catch (ShiftEndNotAfterStartException) {
            self::assertTrue($shift->isOpen());
        }
    }

    public function test_it_refuses_to_clock_out_twice_and_keeps_the_first_end(): void
    {
        $shift = $this->openShift();
        $shift->clockOut(new \DateTimeImmutable(self::END));

        try {
            $shift->clockOut(new \DateTimeImmutable('2026-11-12 01:00:00'));
            self::fail('Expected ShiftAlreadyClosedException.');
        } catch (ShiftAlreadyClosedException) {
            self::assertEquals(new \DateTimeImmutable(self::END), $shift->getEndedAt());
        }
    }

    private function openShift(): Shift
    {
        return Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), null);
    }
}
