<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Payroll\Application\Command\ChangeDayRate\ChangeDayRateCommand;
use App\Payroll\Application\Command\ChangeDayRate\ChangeDayRateHandler;
use App\Payroll\Application\Command\ChangeHourlyRate\ChangeHourlyRateCommand;
use App\Payroll\Application\Command\ChangeHourlyRate\ChangeHourlyRateHandler;
use App\Payroll\Application\Command\DeleteDayRate\DeleteDayRateCommand;
use App\Payroll\Application\Command\DeleteDayRate\DeleteDayRateHandler;
use App\Payroll\Application\Command\DeleteHourlyRate\DeleteHourlyRateCommand;
use App\Payroll\Application\Command\DeleteHourlyRate\DeleteHourlyRateHandler;
use App\Payroll\Application\Command\SetDayRate\SetDayRateCommand;
use App\Payroll\Application\Command\SetDayRate\SetDayRateHandler;
use App\Payroll\Application\Command\SetHourlyRate\SetHourlyRateCommand;
use App\Payroll\Application\Command\SetHourlyRate\SetHourlyRateHandler;
use App\Payroll\Application\LockedPayrollDatesLoader;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Application\Query\GetDayRate\GetDayRateHandler;
use App\Payroll\Application\Query\GetDayRate\GetDayRateQuery;
use App\Payroll\Application\Query\GetHourlyRate\GetHourlyRateHandler;
use App\Payroll\Application\Query\GetHourlyRate\GetHourlyRateQuery;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Application\Query\GetPayRate\GetPayRateHandler;
use App\Payroll\Application\Query\GetPayRate\GetPayRateQuery;
use App\Payroll\Application\Query\GetPayRate\PayRateView;
use App\Payroll\Application\Query\ListDayRates\ListDayRatesHandler;
use App\Payroll\Application\Query\ListDayRates\ListDayRatesQuery;
use App\Payroll\Application\Query\ListHourlyRates\ListHourlyRatesHandler;
use App\Payroll\Application\Query\ListHourlyRates\ListHourlyRatesQuery;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesHandler;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesQuery;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRateNotFoundException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\TimeException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PayRateMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\Tests\Unit\TimeTracking\Application\InMemoryLockedShiftPeriodRepository;
use App\TimeTracking\Application\Query\ListLockedShiftPeriods\ListLockedShiftPeriodsHandler;
use App\TimeTracking\Application\Query\ListLockedShiftPeriods\ListLockedShiftPeriodsQuery;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization (PLN) with an owner, a manager, an employee and a deactivated employee; Organization
 * and Time tracking answer through their real queries over in-memory repositories.
 */
#[CoversClass(SetHourlyRateHandler::class)]
#[CoversClass(ChangeHourlyRateHandler::class)]
#[CoversClass(DeleteHourlyRateHandler::class)]
#[CoversClass(SetDayRateHandler::class)]
#[CoversClass(ChangeDayRateHandler::class)]
#[CoversClass(DeleteDayRateHandler::class)]
#[CoversClass(GetHourlyRateHandler::class)]
#[CoversClass(ListHourlyRatesHandler::class)]
#[CoversClass(GetDayRateHandler::class)]
#[CoversClass(ListDayRatesHandler::class)]
#[CoversClass(GetPayRateHandler::class)]
#[CoversClass(ListPayRatesHandler::class)]
#[CoversClass(HourlyRateView::class)]
#[CoversClass(DayRateView::class)]
#[CoversClass(PayRateView::class)]
#[CoversClass(PayrollActor::class)]
#[CoversClass(LockedPayrollDatesLoader::class)]
#[CoversClass(ListLockedShiftPeriodsHandler::class)]
final class PayRateHandlersTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';
    private const string JOINED = '2026-01-05 09:00:00';

    private MockClock $clock;
    private InMemoryHourlyRateRepository $rates;
    private InMemoryDayRateRepository $dayRates;
    private InMemoryLockedShiftPeriodRepository $locks;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $former;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->rates = new InMemoryHourlyRateRepository();
        $this->dayRates = new InMemoryDayRateRepository();
        $this->locks = new InMemoryLockedShiftPeriodRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->former = UserMother::any('fred@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->former, MembershipRole::Employee, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->former, $joined->modify('+1 month'));
        $organizations->add($this->organization);

        $locks = $this->locks;
        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof ListLockedShiftPeriodsQuery => (new ListLockedShiftPeriodsHandler($locks))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_sets_an_hourly_rate_in_the_organizations_currency(string $actor): void
    {
        $id = $this->setRate($this->user($actor), $this->employee, '2026-12-01', 3050);

        $rate = $this->rates->findById(Uuid::fromString($id)) ?? self::fail('Rate expected.');
        self::assertTrue($rate->getUserId()->equals($this->employee->getId()));
        self::assertSame('2026-12-01', $rate->getEffectiveFrom()->toString());
        self::assertSame(3050, $rate->getAmount());
        self::assertSame('PLN', $rate->getCurrency());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $rate->getCreatedAt());
    }

    public function test_it_lets_a_manager_set_their_own_rate_and_gives_a_deactivated_member_one(): void
    {
        $this->setRate($this->manager, $this->manager, '2026-12-01', 4000);
        $this->setRate($this->owner, $this->former, '2026-02-01', 2800);

        self::assertCount(1, $this->rates->findByUser($this->manager->getId()));
        self::assertCount(1, $this->rates->findByUser($this->former->getId()));
    }

    public function test_it_refuses_an_employee_to_set_rates_even_their_own(): void
    {
        foreach ([$this->employee, $this->owner] as $user) {
            try {
                $this->setRate($this->employee, $user, '2026-12-01', 3000);
                self::fail('An employee set a rate.');
            } catch (PayrollAccessForbiddenException) {
            }
        }
        self::assertSame([], $this->rates->findAll());
    }

    public function test_it_answers_not_found_for_a_user_who_is_not_a_member_and_for_an_actor_who_is_not_one(): void
    {
        try {
            $this->setRate($this->owner, UserMother::any('stranger@example.com'), '2026-12-01', 3000);
            self::fail('A rate was set for a stranger.');
        } catch (MemberNotFoundException) {
        }

        $this->expectException(OrganizationNotFoundException::class);
        $this->setRate($this->former, $this->employee, '2026-12-01', 3000);
    }

    public function test_it_refuses_a_second_rate_from_the_same_date_and_an_invalid_date(): void
    {
        $this->setRate($this->owner, $this->employee, '2026-12-01', 3000);

        try {
            $this->setRate($this->owner, $this->employee, '2026-12-01', 3100);
            self::fail('A second rate from the same date was set.');
        } catch (PayRateDateTakenException) {
        }

        $this->expectException(TimeException::class);
        $this->setRate($this->owner, $this->employee, '2026-02-30', 3100);
    }

    public function test_it_refuses_a_rate_that_would_be_in_effect_in_a_locked_month(): void
    {
        $this->locks->lockMonth(2026, 10);
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-09-01', 2900));

        try {
            $this->setRate($this->owner, $this->employee, '2026-09-15', 3000);
            self::fail('A rate before the locked month, in effect until further notice, was set.');
        } catch (PayRatePeriodLockedException) {
        }

        $this->setRate($this->owner, $this->employee, '2026-11-01', 3000);
        self::assertCount(2, $this->rates->findByUser($this->employee->getId()));
    }

    public function test_it_changes_and_deletes_an_hourly_rate(): void
    {
        $id = $this->setRate($this->owner, $this->employee, '2026-12-01', 3000);
        $this->clock->modify('+1 hour');

        ($this->changeRateHandler())(new ChangeHourlyRateCommand($this->organizationId(), $this->manager->getId()->toRfc4122(), $id, '2026-12-15', 3300));

        $rate = $this->rates->findById(Uuid::fromString($id)) ?? self::fail('Rate expected.');
        self::assertSame('2026-12-15', $rate->getEffectiveFrom()->toString());
        self::assertSame(3300, $rate->getAmount());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 11:00:00'), $rate->getUpdatedAt());

        (new DeleteHourlyRateHandler($this->rates, $this->loader(), $this->queryBus))(new DeleteHourlyRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $id));

        self::assertNull($this->rates->findById(Uuid::fromString($id)));
    }

    public function test_it_answers_not_found_for_an_unknown_rate(): void
    {
        foreach (['not-a-uuid', Uuid::v7()->toRfc4122()] as $id) {
            try {
                ($this->changeRateHandler())(new ChangeHourlyRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $id, '2026-12-15', 3300));
                self::fail('An unknown rate was changed.');
            } catch (PayRateNotFoundException) {
            }
        }

        $this->expectException(PayRateNotFoundException::class);
        (new DeleteDayRateHandler($this->dayRates, $this->loader(), $this->queryBus))(new DeleteDayRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), Uuid::v7()->toRfc4122()));
    }

    public function test_it_refuses_to_delete_a_rate_in_effect_in_a_locked_month(): void
    {
        $september = PayRateMother::hourly($this->employee->getId(), '2026-09-01');
        $this->rates->add($september);
        $this->locks->lockMonth(2026, 10);

        $this->expectException(PayRatePeriodLockedException::class);

        (new DeleteHourlyRateHandler($this->rates, $this->loader(), $this->queryBus))(new DeleteHourlyRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $september->getId()->toRfc4122()));
    }

    public function test_it_sets_changes_and_deletes_a_day_rate(): void
    {
        $id = Uuid::v7()->toRfc4122();
        (new SetDayRateHandler($this->dayRates, $this->loader(), $this->queryBus, $this->clock))(new SetDayRateCommand($this->organizationId(), $this->manager->getId()->toRfc4122(), $id, $this->employee->getId()->toRfc4122(), '2026-12-24', 4500));

        try {
            (new SetDayRateHandler($this->dayRates, $this->loader(), $this->queryBus, $this->clock))(new SetDayRateCommand($this->organizationId(), $this->manager->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), $this->employee->getId()->toRfc4122(), '2026-12-24', 4000));
            self::fail('A second day rate on the same date was set.');
        } catch (PayRateDateTakenException) {
        }

        (new ChangeDayRateHandler($this->dayRates, $this->loader(), $this->queryBus, $this->clock))(new ChangeDayRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $id, '2026-12-31', 5000));
        $dayRate = $this->dayRates->findById(Uuid::fromString($id)) ?? self::fail('Day rate expected.');
        self::assertSame('2026-12-31', $dayRate->getDate()->toString());
        self::assertSame(5000, $dayRate->getAmount());
        self::assertSame('PLN', $dayRate->getCurrency());

        (new DeleteDayRateHandler($this->dayRates, $this->loader(), $this->queryBus))(new DeleteDayRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $id));
        self::assertNull($this->dayRates->findById(Uuid::fromString($id)));
    }

    public function test_it_refuses_a_day_rate_on_a_locked_date_and_an_employee(): void
    {
        $this->locks->lockMonth(2026, 10);
        $set = new SetDayRateHandler($this->dayRates, $this->loader(), $this->queryBus, $this->clock);

        try {
            $set(new SetDayRateCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), $this->employee->getId()->toRfc4122(), '2026-10-31', 4500));
            self::fail('A day rate on a locked date was set.');
        } catch (PayRatePeriodLockedException) {
        }

        $this->expectException(PayrollAccessForbiddenException::class);
        $set(new SetDayRateCommand($this->organizationId(), $this->employee->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), $this->employee->getId()->toRfc4122(), '2026-11-02', 4500));
    }

    public function test_it_shows_a_rate_with_the_date_until_which_it_is_in_effect(): void
    {
        $old = PayRateMother::hourly($this->employee->getId(), '2026-03-01', 2800);
        $new = PayRateMother::hourly($this->employee->getId(), '2026-09-01', 3000);
        $this->rates->add($old);
        $this->rates->add($new);

        $view = $this->getRate($this->employee, $old->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame('2026-03-01', $view->effectiveFrom);
        self::assertSame('2026-08-31', $view->effectiveTo);
        self::assertSame(2800, $view->amount);
        self::assertSame('PLN', $view->currency);
        self::assertNull($this->getRate($this->employee, $new->getId()->toRfc4122())?->effectiveTo);
        self::assertNull($this->getRate($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->getRate($this->former, $old->getId()->toRfc4122()), 'A deactivated member sees nothing.');
    }

    public function test_it_refuses_an_employee_someone_elses_rate_or_day_rate(): void
    {
        $rate = PayRateMother::hourly($this->manager->getId(), '2026-03-01');
        $dayRate = PayRateMother::day($this->manager->getId(), '2026-12-31');
        $this->rates->add($rate);
        $this->dayRates->add($dayRate);

        try {
            $this->getRate($this->employee, $rate->getId()->toRfc4122());
            self::fail('An employee saw someone else\'s rate.');
        } catch (PayrollAccessForbiddenException) {
        }

        $this->expectException(PayrollAccessForbiddenException::class);
        (new GetDayRateHandler($this->dayRates, $this->queryBus))(new GetDayRateQuery($this->organizationId(), $this->employee->getId()->toRfc4122(), $dayRate->getId()->toRfc4122()));
    }

    public function test_it_lists_everyones_rates_for_managers_and_an_employees_own(): void
    {
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-03-01', 2800));
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-09-01', 3000));
        $this->rates->add(PayRateMother::hourly($this->former->getId(), '2026-01-01', 2700));
        $this->dayRates->add(PayRateMother::day($this->employee->getId(), '2026-12-31'));
        $this->dayRates->add(PayRateMother::day($this->former->getId(), '2026-01-31'));

        $all = $this->listRates($this->manager);
        self::assertNotNull($all);
        self::assertCount(3, $all);
        $mine = $this->listRates($this->employee);
        self::assertSame([['2026-09-01', null], ['2026-03-01', '2026-08-31']], array_map(static fn (HourlyRateView $view): array => [$view->effectiveFrom, $view->effectiveTo], $mine ?? []));
        self::assertEquals($mine, $this->listRates($this->employee, $this->employee->getId()->toRfc4122()));
        self::assertCount(1, $this->listRates($this->owner, $this->former->getId()->toRfc4122()) ?? []);
        self::assertNull($this->listRates($this->former));

        $listDayRates = new ListDayRatesHandler($this->dayRates, $this->queryBus);
        self::assertCount(2, $listDayRates(new ListDayRatesQuery($this->organizationId(), $this->owner->getId()->toRfc4122())) ?? []);
        self::assertSame(['2026-12-31'], array_map(static fn (DayRateView $view): string => $view->date, $listDayRates(new ListDayRatesQuery($this->organizationId(), $this->employee->getId()->toRfc4122())) ?? []));

        try {
            $this->listRates($this->employee, $this->manager->getId()->toRfc4122());
            self::fail('An employee listed someone else\'s rates.');
        } catch (PayrollAccessForbiddenException) {
        }

        $this->expectException(MemberNotFoundException::class);
        $this->listRates($this->owner, Uuid::v7()->toRfc4122());
    }

    public function test_it_gives_the_rate_on_a_date_day_rate_first(): void
    {
        $userId = $this->employee->getId();
        $hourly = PayRateMother::hourly($userId, '2026-12-01', 3000);
        $dayRate = PayRateMother::day($userId, '2026-12-31', 4500);
        $this->rates->add(PayRateMother::hourly($userId, '2026-06-01', 2800));
        $this->rates->add($hourly);
        $this->dayRates->add($dayRate);
        $handler = new GetPayRateHandler($this->rates, $this->dayRates);

        self::assertEquals(new PayRateView($userId->toRfc4122(), '2026-12-30', PayRateView::HOURLY, $hourly->getId()->toRfc4122(), 3000, 'PLN'), $handler(new GetPayRateQuery($userId->toRfc4122(), '2026-12-30')));
        self::assertEquals(new PayRateView($userId->toRfc4122(), '2026-12-31', PayRateView::DAY, $dayRate->getId()->toRfc4122(), 4500, 'PLN'), $handler(new GetPayRateQuery($userId->toRfc4122(), '2026-12-31')));
        self::assertSame(2800, $handler(new GetPayRateQuery($userId->toRfc4122(), '2026-11-30'))?->amount);
        self::assertNull($handler(new GetPayRateQuery($userId->toRfc4122(), '2026-05-31')));
        self::assertNull($handler(new GetPayRateQuery($this->manager->getId()->toRfc4122(), '2026-12-31')));
        self::assertNull($handler(new GetPayRateQuery('not-a-uuid', '2026-12-31')));
    }

    public function test_it_lists_the_rate_of_each_member_on_each_date_of_a_period(): void
    {
        $anna = $this->employee->getId();
        $fred = $this->former->getId();
        $this->rates->add(PayRateMother::hourly($anna, '2026-12-01', 3000));
        $this->rates->add(PayRateMother::hourly($anna, '2026-12-30', 3200));
        $this->rates->add(PayRateMother::hourly($fred, '2026-01-01', 2700));
        $this->dayRates->add(PayRateMother::day($anna, '2026-12-31', 4500));
        $this->dayRates->add(PayRateMother::day($fred, '2027-01-01', 9900));
        $handler = new ListPayRatesHandler($this->rates, $this->dayRates);

        $views = $handler(new ListPayRatesQuery('2026-12-29', '2026-12-31'));

        $rows = array_map(static fn (PayRateView $view): array => [$view->date, $view->userId, $view->kind, $view->amount], $views);
        $byDate = static fn (string $date): array => array_values(array_filter($rows, static fn (array $row): bool => $row[0] === $date));
        self::assertCount(6, $rows);
        self::assertEqualsCanonicalizing([['2026-12-29', $anna->toRfc4122(), 'hourly', 3000], ['2026-12-29', $fred->toRfc4122(), 'hourly', 2700]], $byDate('2026-12-29'));
        self::assertEqualsCanonicalizing([['2026-12-30', $anna->toRfc4122(), 'hourly', 3200], ['2026-12-30', $fred->toRfc4122(), 'hourly', 2700]], $byDate('2026-12-30'));
        self::assertEqualsCanonicalizing([['2026-12-31', $anna->toRfc4122(), 'day', 4500], ['2026-12-31', $fred->toRfc4122(), 'hourly', 2700]], $byDate('2026-12-31'));
        self::assertSame(['2026-12-29', '2026-12-29', '2026-12-30', '2026-12-30', '2026-12-31', '2026-12-31'], array_column($rows, 0));

        self::assertCount(3, $handler(new ListPayRatesQuery('2026-12-29', '2026-12-31', $anna->toRfc4122())));
        self::assertSame([], $handler(new ListPayRatesQuery('2025-01-01', '2025-01-31')));
    }

    private function setRate(User $actor, User $user, string $effectiveFrom, int $amount): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new SetHourlyRateHandler($this->rates, $this->loader(), $this->queryBus, $this->clock))(new SetHourlyRateCommand(
            $this->organizationId(),
            $actor->getId()->toRfc4122(),
            $id,
            $user->getId()->toRfc4122(),
            $effectiveFrom,
            $amount,
        ));

        return $id;
    }

    private function changeRateHandler(): ChangeHourlyRateHandler
    {
        return new ChangeHourlyRateHandler($this->rates, $this->loader(), $this->queryBus, $this->clock);
    }

    private function getRate(User $actor, string $rateId): ?HourlyRateView
    {
        return (new GetHourlyRateHandler($this->rates, $this->queryBus))(new GetHourlyRateQuery($this->organizationId(), $actor->getId()->toRfc4122(), $rateId));
    }

    /** @return list<HourlyRateView>|null */
    private function listRates(User $actor, ?string $userId = null): ?array
    {
        return (new ListHourlyRatesHandler($this->rates, $this->queryBus))(new ListHourlyRatesQuery($this->organizationId(), $actor->getId()->toRfc4122(), $userId));
    }

    private function loader(): LockedPayrollDatesLoader
    {
        return new LockedPayrollDatesLoader($this->queryBus);
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function user(string $name): User
    {
        return match ($name) {
            'owner' => $this->owner,
            'manager' => $this->manager,
            default => throw new \LogicException($name),
        };
    }
}
