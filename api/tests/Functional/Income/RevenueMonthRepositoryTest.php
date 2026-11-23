<?php

declare(strict_types=1);

namespace App\Tests\Functional\Income;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Model\RevenueEntryChangeKind;
use App\Income\Domain\Model\RevenueEntrySnapshot;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Model\RevenueMonthActionKind;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryChangeRepositoryInterface;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Income\Infrastructure\Doctrine\Orm\Repository\RevenueEntryChangeRepository;
use App\Income\Infrastructure\Doctrine\Orm\Repository\RevenueMonthRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * RevenueMonthRepository and RevenueEntryChangeRepository against PostgreSQL (ADR 0034): one month
 * per organization, its actions, the closed months, the change history by entry and by period, and
 * the TenantFilter.
 */
#[CoversClass(RevenueMonthRepository::class)]
#[CoversClass(RevenueEntryChangeRepository::class)]
final class RevenueMonthRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private RevenueMonthRepositoryInterface $months;
    private RevenueEntryChangeRepositoryInterface $changes;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->months = self::getContainer()->get(RevenueMonthRepositoryInterface::class);
        $this->changes = self::getContainer()->get(RevenueEntryChangeRepositoryInterface::class);
    }

    public function test_it_stores_a_month_with_its_actions_and_lists_the_closed_ones(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $owner = Uuid::v7();
        $october = $this->closed('2026-10', $owner);
        $this->closed('2026-09', $owner);
        $october->reopen($owner, MembershipRole::Owner, new \DateTimeImmutable('2026-11-03 10:00:00'));
        $this->months->save($october);
        $this->entityManager->clear();

        $found = $this->months->findByMonth(BusinessPeriod::monthFromString('2026-10'));
        self::assertNotNull($found);
        self::assertFalse($found->isClosed());
        self::assertSame([RevenueMonthActionKind::Closed, RevenueMonthActionKind::Reopened], array_map(static fn ($action) => $action->getKind(), $found->getActions()));
        self::assertNull($this->months->findByMonth(BusinessPeriod::monthFromString('2026-08')));

        $locks = $this->months->locks();
        self::assertTrue($locks->isClosed(BusinessDate::fromString('2026-09-30')));
        self::assertFalse($locks->isClosed(BusinessDate::fromString('2026-10-01')));
    }

    public function test_it_rejects_a_second_row_for_the_month(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->closed('2026-10', Uuid::v7());

        $this->expectException(RevenueMonthAlreadyClosedException::class);
        $this->closed('2026-10', Uuid::v7());
    }

    public function test_it_shows_only_the_current_organizations_months_and_changes(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->closed('2026-10', Uuid::v7());
        $change = $this->change('2026-10-20');

        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->entityManager->clear();

        self::assertNull($this->months->findByMonth(BusinessPeriod::monthFromString('2026-10')));
        self::assertFalse($this->months->locks()->any());
        self::assertSame([], $this->changes->findByEntryId($change->getEntryId()));
        self::assertSame([], $this->changes->findInPeriod(BusinessPeriod::monthFromString('2026-10')));
    }

    public function test_it_lists_the_changes_of_an_entry_and_of_a_period_newest_first(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $first = $this->change('2026-10-20', at: '2026-10-21 08:00:00');
        $second = $this->change('2026-10-21', at: '2026-10-22 08:00:00');
        $this->change('2026-11-01', at: '2026-11-02 08:00:00');
        $this->entityManager->clear();

        $inOctober = $this->changes->findInPeriod(BusinessPeriod::monthFromString('2026-10'));
        self::assertSame([$second->getId()->toRfc4122(), $first->getId()->toRfc4122()], array_map(static fn (RevenueEntryChange $change): string => $change->getId()->toRfc4122(), $inOctober));
        $byEntry = $this->changes->findByEntryId($first->getEntryId());
        self::assertCount(1, $byEntry);
        self::assertEquals($first->getAfter(), $byEntry[0]->getAfter());
        self::assertSame(RevenueEntryChangeKind::Created, $byEntry[0]->getKind());
    }

    private function closed(string $month, Uuid $owner): RevenueMonth
    {
        $closed = RevenueMonth::closeFirst(Uuid::v7(), BusinessPeriod::monthFromString($month), $owner, MembershipRole::Owner, BusinessDate::fromString('2026-11-02'), new \DateTimeImmutable('2026-11-02 09:00:00'));
        $this->months->add($closed);

        return $closed;
    }

    /** A change row of an entry that was never stored: the history keeps no foreign key to it. */
    private function change(string $date, string $at = '2026-11-02 09:00:00'): RevenueEntryChange
    {
        $register = Register::create(Uuid::v7(), 'Bar '.$date, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
        self::getContainer()->get(RegisterRepositoryInterface::class)->add($register);
        $methods = self::getContainer()->get(PaymentMethodRepositoryInterface::class);
        $cash = PaymentMethod::create(Uuid::v7(), 'Cash '.$date, null, $methods->nextPosition(), false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
        $methods->add($cash);
        $entry = RevenueEntry::record(Uuid::v7(), $register, BusinessDate::fromString($date), 'PLN', 'PL', [new PaymentAmount($cash, 10_000)], [], 'Note', Uuid::v7(), MembershipRole::Owner, BusinessDate::fromString('2026-11-13'), new \DateTimeImmutable($at));

        $change = new RevenueEntryChange(Uuid::v7(), $entry, RevenueEntryChangeKind::Created, null, RevenueEntrySnapshot::of($entry), null, Uuid::v7(), new \DateTimeImmutable($at));
        $this->changes->add($change);

        return $change;
    }
}
