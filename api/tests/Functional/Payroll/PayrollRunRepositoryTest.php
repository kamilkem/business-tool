<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\PayrollSources;
use App\Payroll\Domain\Model\Rounding;
use App\Payroll\Domain\Model\ShiftRate;
use App\Payroll\Domain\Model\ShiftRateKind;
use App\Payroll\Domain\Model\WorkedShift;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Payroll\Infrastructure\Doctrine\Orm\Repository\PayrollRunRepository;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(PayrollRunRepository::class)]
final class PayrollRunRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private PayrollRunRepositoryInterface $runs;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->runs = self::getContainer()->get(PayrollRunRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function test_it_stores_a_run_with_its_paychecks_lines_and_adjustments_and_regenerates_it_in_place(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $anna = Uuid::v7();
        $shift = Uuid::v7();
        $run = $this->generated(10, [new WorkedShift($shift, $anna, BusinessDate::fromString('2026-10-03'), 3_600)], $anna);
        $run->addAdjustment(Uuid::v7(), $anna, 'Bonus', 500, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-20 10:00:00'));
        $this->runs->add($run);
        $this->clear();

        $stored = $this->runs->findById($run->getId()) ?? throw new \LogicException('Run expected.');
        self::assertSame(3_500, $stored->total());

        // The same shift, now longer, plus a new one: the line is updated in place in one flush.
        $stored->regenerate(null, $this->sources(10, [
            new WorkedShift($shift, $anna, BusinessDate::fromString('2026-10-03'), 7_200),
            new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-10-04'), 3_600),
        ], $anna), Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-20 11:00:00'));
        $this->runs->save($stored);
        $this->clear();

        $regenerated = $this->runs->findById($run->getId()) ?? throw new \LogicException('Run expected.');
        self::assertSame(9_500, $regenerated->total());
        self::assertCount(2, ($regenerated->findPaycheck($anna) ?? throw new \LogicException('Paycheck expected.'))->shiftLines());
    }

    public function test_it_finds_runs_by_month_newest_first_and_reports_a_second_run_of_a_month_as_taken(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $anna = Uuid::v7();
        $this->runs->add($this->generated(9, [new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-09-03'), 3_600)], $anna));
        $this->runs->add($october = $this->generated(10, [new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-10-03'), 3_600)], $anna));
        $this->clear();

        self::assertSame(['2026-10-01', '2026-09-01'], array_map(static fn (PayrollRun $run): string => $run->month()->first->toString(), $this->runs->findAll()));
        self::assertTrue($october->getId()->equals($this->runs->findByMonth(BusinessPeriod::month(2026, 10))?->getId() ?? Uuid::v7()));
        self::assertNull($this->runs->findByMonth(BusinessPeriod::month(2026, 8)));

        $this->expectException(PayrollRunMonthTakenException::class);

        $this->runs->add($this->generated(10, [new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-10-05'), 3_600)], $anna));
    }

    public function test_it_isolates_the_runs_of_each_organization(): void
    {
        $anna = Uuid::v7();
        $theirs = OrganizationFactory::createOne();
        $this->switchTo($theirs);
        $theirRun = $this->generated(10, [new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-10-03'), 3_600)], $anna);
        $this->runs->add($theirRun);
        $this->switchTo(OrganizationFactory::createOne());
        // The same month in another organization is no conflict.
        $this->runs->add($this->generated(10, [new WorkedShift(Uuid::v7(), $anna, BusinessDate::fromString('2026-10-03'), 7_200)], $anna));
        $this->entityManager->clear();

        self::assertNull($this->runs->findById($theirRun->getId()));
        self::assertCount(1, $this->runs->findAll());

        $this->tenantContext->reset();
        $this->entityManager->clear();
        self::assertSame([], $this->runs->findAll(), 'No organization selected: no rows.');
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM paycheck_line WHERE organization_id IS NULL'));
    }

    /** @param list<WorkedShift> $shifts */
    private function generated(int $month, array $shifts, Uuid $paid): PayrollRun
    {
        return PayrollRun::generate(
            Uuid::v7(),
            Rounding::exact(),
            $this->sources($month, $shifts, $paid),
            BusinessDate::fromString('2026-11-20'),
            Uuid::v7(),
            MembershipRole::Owner,
            new \DateTimeImmutable('2026-11-20 10:00:00'),
        );
    }

    /** @param list<WorkedShift> $shifts */
    private function sources(int $month, array $shifts, Uuid $paid): PayrollSources
    {
        $period = BusinessPeriod::month(2026, $month);
        $rates = [];
        for ($date = $period->first; !$date->isAfter($period->last); $date = $date->next()) {
            $rates[] = new ShiftRate($paid, $date, ShiftRateKind::Hourly, Uuid::v7(), 3_000, 'PLN');
        }

        return new PayrollSources($period, $shifts, $rates, 0, 'PLN');
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo($organization);
    }

    /** Starts from an empty identity map, as a new request would, with the organization still selected. */
    private function clear(): void
    {
        $organizationId = $this->tenantContext->getOrganization()->getId();
        $this->entityManager->clear();
        $this->switchTo($this->entityManager->find(Organization::class, $organizationId) ?? throw new \LogicException('Organization expected.'));
    }
}
