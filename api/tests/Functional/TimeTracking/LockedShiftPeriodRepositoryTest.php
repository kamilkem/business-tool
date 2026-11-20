<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\LockedShiftPeriodMother;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;
use App\TimeTracking\Infrastructure\Doctrine\Orm\Repository\LockedShiftPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** LockedShiftPeriodRepository against PostgreSQL: DATE columns, lookups and the TenantFilter. */
#[CoversClass(LockedShiftPeriodRepository::class)]
final class LockedShiftPeriodRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private LockedShiftPeriodRepositoryInterface $periods;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->periods = self::getContainer()->get(LockedShiftPeriodRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function test_it_stores_a_period_and_finds_it_by_locker_and_by_date(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $runId = Uuid::v7();
        $october = LockedShiftPeriodMother::month(2026, 10, $runId);
        $this->periods->add($october);
        $this->periods->add(LockedShiftPeriodMother::month(2026, 9));
        $this->entityManager->clear();

        $found = $this->periods->findByLockedBy($runId);
        self::assertNotNull($found);
        self::assertTrue($found->getId()->equals($october->getId()));
        self::assertTrue(BusinessPeriod::month(2026, 10)->equals($found->getPeriod()));
        self::assertEquals(new \DateTimeImmutable('2026-11-05 10:00:00'), $found->getLockedAt());

        self::assertSame($october->getId()->toRfc4122(), $this->periods->findContaining(BusinessDate::of(2026, 10, 1))?->getId()->toRfc4122());
        self::assertSame($october->getId()->toRfc4122(), $this->periods->findContaining(BusinessDate::of(2026, 10, 31))?->getId()->toRfc4122());
        self::assertNull($this->periods->findContaining(BusinessDate::of(2026, 11, 1)));
        self::assertSame(['2026-09-01', '2026-10-01'], array_map(static fn ($period): string => $period->getPeriod()->first->toString(), $this->periods->findAll()));
        self::assertNull($this->periods->findByLockedBy(Uuid::v7()));
    }

    public function test_it_removes_a_period(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $period = LockedShiftPeriodMother::month(2026, 10);
        $this->periods->add($period);

        $this->periods->remove($period);
        $this->entityManager->clear();

        self::assertSame([], $this->periods->findAll());
    }

    public function test_it_sees_the_current_organizations_periods_only(): void
    {
        $organization = OrganizationFactory::createOne();
        $other = OrganizationFactory::createOne();
        $this->switchTo($other);
        $runId = Uuid::v7();
        $this->periods->add(LockedShiftPeriodMother::month(2026, 10, $runId));
        $this->entityManager->clear();

        $this->switchTo($organization);

        self::assertSame([], $this->periods->findAll());
        self::assertNull($this->periods->findByLockedBy($runId));
        self::assertNull($this->periods->findContaining(BusinessDate::of(2026, 10, 15)));

        $this->tenantContext->reset();
        self::assertSame([], $this->periods->findAll(), 'With no organization selected, nothing is visible.');
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
    }
}
