<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\Command\ChangeDayCloseSettings\ChangeDayCloseSettingsCommand;
use App\CashFlow\Application\Command\ChangeDayCloseSettings\ChangeDayCloseSettingsHandler;
use App\CashFlow\Application\DayCloseRules;
use App\CashFlow\Application\Query\GetDayCloseSettings\DayCloseSettingsView;
use App\CashFlow\Application\Query\GetDayCloseSettings\GetDayCloseSettingsHandler;
use App\CashFlow\Application\Query\GetDayCloseSettings\GetDayCloseSettingsQuery;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidDayEndTimeException;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization with an owner, a manager, an employee and a former manager; the Organization
 * module answers through its real queries over an in-memory repository.
 */
#[CoversClass(ChangeDayCloseSettingsHandler::class)]
#[CoversClass(GetDayCloseSettingsHandler::class)]
#[CoversClass(DayCloseRules::class)]
final class DayCloseSettingsHandlersTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private InMemoryDayCloseSettingsRepository $settings;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $formerManager;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->settings = new InMemoryDayCloseSettingsRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->formerManager = UserMother::any('fred@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->formerManager, MembershipRole::Manager, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->formerManager);
        $organizations->add($this->organization);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    public function test_it_gives_the_defaults_to_every_active_member_until_changed(): void
    {
        foreach ([$this->owner, $this->manager, $this->employee] as $member) {
            self::assertEquals(new DayCloseSettingsView('day_end_time', '05:00', null), $this->get($member));
        }
        self::assertTrue((new DayCloseRules($this->settings))->current()->equals(DayCloseRule::default()));
    }

    public function test_it_creates_the_settings_on_the_first_change_and_changes_them_after(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->change($this->manager, 'employee_picks', '04:30', $id);

        self::assertEquals(new DayCloseSettingsView('employee_picks', '04:30', new \DateTimeImmutable(self::NOW)), $this->get($this->employee));
        self::assertSame($id, $this->settings->findCurrent()?->getId()->toRfc4122());

        $this->clock->modify('+1 day');
        $this->change($this->owner, 'day_end_time', '06:00');

        self::assertEquals(new DayCloseSettingsView('day_end_time', '06:00', new \DateTimeImmutable('2026-11-23 10:00:00')), $this->get($this->manager));
        $settings = $this->settings->findCurrent();
        self::assertNotNull($settings);
        self::assertSame($id, $settings->getId()->toRfc4122(), 'The same settings, changed.');
        self::assertTrue($settings->getUpdatedBy()->equals($this->owner->getId()));
        self::assertSame(1, $this->settings->saves);

        $rule = (new DayCloseRules($this->settings))->current();
        self::assertSame(DayCloseDateMode::DayEndTime, $rule->mode);
        self::assertSame('06:00', $rule->dayEndsAt->toString());
    }

    public function test_it_refuses_employees_and_stores_nothing(): void
    {
        $this->expectException(CashFlowAccessForbiddenException::class);

        try {
            $this->change($this->employee, 'employee_picks', '04:30');
        } finally {
            self::assertNull($this->settings->findCurrent());
        }
    }

    public function test_it_refuses_employees_a_read_to_manage(): void
    {
        self::assertNotNull($this->get($this->manager, toManage: true));

        $this->expectException(CashFlowAccessForbiddenException::class);
        $this->get($this->employee, toManage: true);
    }

    public function test_it_treats_a_former_manager_as_not_a_member(): void
    {
        self::assertNull($this->get($this->formerManager));

        $this->expectException(OrganizationNotFoundException::class);
        $this->change($this->formerManager, 'employee_picks', '04:30');
    }

    public function test_it_refuses_a_day_end_time_after_noon(): void
    {
        $this->expectException(InvalidDayEndTimeException::class);

        try {
            $this->change($this->owner, 'day_end_time', '18:00');
        } finally {
            self::assertNull($this->settings->findCurrent());
        }
    }

    private function change(User $actor, string $mode, string $dayEndsAt, ?string $id = null): void
    {
        (new ChangeDayCloseSettingsHandler($this->settings, $this->queryBus, $this->clock))(new ChangeDayCloseSettingsCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $id ?? Uuid::v7()->toRfc4122(),
            $mode,
            $dayEndsAt,
        ));
    }

    private function get(User $actor, bool $toManage = false): ?DayCloseSettingsView
    {
        return (new GetDayCloseSettingsHandler($this->settings, $this->queryBus))(new GetDayCloseSettingsQuery(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $toManage,
        ));
    }
}
