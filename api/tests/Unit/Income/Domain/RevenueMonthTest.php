<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Domain;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Income\Domain\Exception\RevenueMonthNotClosedException;
use App\Income\Domain\Exception\RevenueMonthNotEndedException;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Model\RevenueMonthAction;
use App\Income\Domain\Model\RevenueMonthActionKind;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RevenueMonthTest extends TestCase
{
    private Uuid $owner;
    private Uuid $otherOwner;

    protected function setUp(): void
    {
        $this->owner = Uuid::v7();
        $this->otherOwner = Uuid::v7();
    }

    public function test_it_lets_an_owner_close_a_month_that_has_ended(): void
    {
        $month = $this->closed('2026-10', today: '2026-11-01');

        self::assertTrue($month->isClosed());
        self::assertSame('2026-10', $month->monthString());
        self::assertTrue($this->owner->equals($month->getClosedBy()));
        self::assertEquals(new \DateTimeImmutable('2026-11-01 09:00:00'), $month->getClosedAt());
        self::assertSame([[RevenueMonthActionKind::Closed, $this->owner->toRfc4122()]], $this->actions($month));
    }

    public function test_it_refuses_to_close_a_month_on_its_last_day_or_before(): void
    {
        $this->expectException(RevenueMonthNotEndedException::class);
        $this->expectExceptionMessage('The month 2026-10 has not ended yet (today is 2026-10-31).');

        $this->closed('2026-10', today: '2026-10-31');
    }

    public function test_it_lets_only_owners_close_and_reopen(): void
    {
        foreach ([MembershipRole::Manager, MembershipRole::Employee] as $role) {
            try {
                RevenueMonth::closeFirst(Uuid::v7(), BusinessPeriod::monthFromString('2026-10'), Uuid::v7(), $role, BusinessDate::fromString('2026-11-02'), new \DateTimeImmutable());
                self::fail('RevenueMonthCloseForbiddenException expected.');
            } catch (RevenueMonthCloseForbiddenException $e) {
                self::assertSame('income.revenue_month_close_forbidden', $e->errorCode());
            }
        }

        $month = $this->closed('2026-10');
        $this->expectException(RevenueMonthCloseForbiddenException::class);
        $month->reopen(Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable());
    }

    public function test_it_reopens_and_closes_again_and_keeps_every_action(): void
    {
        $month = $this->closed('2026-10');

        $month->reopen($this->otherOwner, MembershipRole::Owner, new \DateTimeImmutable('2026-11-03 10:00:00'));
        self::assertFalse($month->isClosed());
        self::assertNull($month->getClosedBy());
        self::assertNull($month->getClosedAt());

        $month->close($this->otherOwner, MembershipRole::Owner, BusinessDate::fromString('2026-11-04'), new \DateTimeImmutable('2026-11-04 10:00:00'));
        self::assertTrue($this->otherOwner->equals($month->getClosedBy()));
        self::assertSame([
            [RevenueMonthActionKind::Closed, $this->owner->toRfc4122()],
            [RevenueMonthActionKind::Reopened, $this->otherOwner->toRfc4122()],
            [RevenueMonthActionKind::Closed, $this->otherOwner->toRfc4122()],
        ], $this->actions($month));
    }

    public function test_it_refuses_to_close_a_closed_month_or_reopen_an_open_one(): void
    {
        $month = $this->closed('2026-10');
        try {
            $month->close($this->owner, MembershipRole::Owner, BusinessDate::fromString('2026-11-04'), new \DateTimeImmutable());
            self::fail('RevenueMonthAlreadyClosedException expected.');
        } catch (RevenueMonthAlreadyClosedException $e) {
            self::assertSame(['month' => '2026-10'], $e->errorParameters());
        }

        $month->reopen($this->owner, MembershipRole::Owner, new \DateTimeImmutable());
        $this->expectException(RevenueMonthNotClosedException::class);
        $month->reopen($this->owner, MembershipRole::Owner, new \DateTimeImmutable());
    }

    public function test_it_locks_the_dates_of_closed_months_only(): void
    {
        $locks = new RevenueMonthLocks(['2026-10']);

        self::assertTrue($locks->any());
        self::assertFalse(RevenueMonthLocks::none()->any());
        self::assertTrue($locks->isClosed(BusinessDate::fromString('2026-10-01')));
        self::assertTrue($locks->isClosed(BusinessDate::fromString('2026-10-31')));
        self::assertFalse($locks->isClosed(BusinessDate::fromString('2026-11-01')));
        $locks->assertOpen(BusinessDate::fromString('2026-09-30'));

        try {
            $locks->assertOpen(BusinessDate::fromString('2026-10-15'));
            self::fail('RevenueMonthClosedException expected.');
        } catch (RevenueMonthClosedException $e) {
            self::assertSame('income.revenue_month_closed', $e->errorCode());
            self::assertSame(['month' => '2026-10', 'businessDate' => '2026-10-15'], $e->errorParameters());
        }
    }

    private function closed(string $month, string $today = '2026-11-02'): RevenueMonth
    {
        return RevenueMonth::closeFirst(Uuid::v7(), BusinessPeriod::monthFromString($month), $this->owner, MembershipRole::Owner, BusinessDate::fromString($today), new \DateTimeImmutable($today.' 09:00:00'));
    }

    /** @return list<array{RevenueMonthActionKind, string}> */
    private function actions(RevenueMonth $month): array
    {
        return array_map(static fn (RevenueMonthAction $action): array => [$action->getKind(), $action->getActorId()->toRfc4122()], $month->getActions());
    }
}
