<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payroll;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetPayRate\GetPayRateQuery;
use App\Payroll\Application\Query\GetPayRate\PayRateView;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesQuery;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Payroll\Infrastructure\Doctrine\Orm\Repository\DayRateRepository;
use App\Payroll\Infrastructure\Doctrine\Orm\Repository\HourlyRateRepository;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\PayRateMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** The rate repositories against PostgreSQL: DATE columns, ordering, unique constraints and the TenantFilter. */
#[CoversClass(HourlyRateRepository::class)]
#[CoversClass(DayRateRepository::class)]
final class PayRateRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private HourlyRateRepositoryInterface $rates;
    private DayRateRepositoryInterface $dayRates;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->rates = self::getContainer()->get(HourlyRateRepositoryInterface::class);
        $this->dayRates = self::getContainer()->get(DayRateRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function test_it_stores_hourly_rates_and_finds_them_by_person_oldest_first(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $anna = Uuid::v7();
        $ben = Uuid::v7();
        $this->rates->add(PayRateMother::hourly($anna, '2026-09-01', 3000));
        $this->rates->add(PayRateMother::hourly($anna, '2026-03-01', 2800));
        $this->rates->add(PayRateMother::hourly($ben, '2026-01-01', 5000));
        $this->entityManager->clear();

        $annas = $this->rates->findByUser($anna);
        self::assertSame(['2026-03-01', '2026-09-01'], array_map(static fn ($rate): string => $rate->getEffectiveFrom()->toString(), $annas));
        self::assertSame(2800, $annas[0]->getAmount());
        self::assertSame('PLN', $annas[0]->getCurrency());
        self::assertSame(['2026-09-01', '2026-03-01'], array_map(static fn ($rate): string => $rate->getEffectiveFrom()->toString(), $this->rates->findAll($anna)));
        self::assertCount(3, $this->rates->findAll());
    }

    public function test_it_reports_a_second_hourly_rate_from_the_same_date_as_taken(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $anna = Uuid::v7();
        $this->rates->add(PayRateMother::hourly($anna, '2026-09-01'));

        $this->expectException(PayRateDateTakenException::class);

        $this->rates->add(PayRateMother::hourly($anna, '2026-09-01'));
    }

    public function test_it_finds_day_rates_by_date_and_in_a_period_and_reports_a_duplicate(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $anna = Uuid::v7();
        $this->dayRates->add(PayRateMother::day($anna, '2026-12-31', 4500));
        $this->dayRates->add(PayRateMother::day($anna, '2026-12-24', 4000));
        $this->dayRates->add(PayRateMother::day(Uuid::v7(), '2026-12-31', 5000));
        $this->entityManager->clear();

        self::assertSame(4500, $this->dayRates->findByUserAndDate($anna, BusinessDate::of(2026, 12, 31))?->getAmount());
        self::assertNull($this->dayRates->findByUserAndDate($anna, BusinessDate::of(2026, 12, 30)));
        self::assertCount(2, $this->dayRates->findIn(BusinessPeriod::fromStrings('2026-12-25', '2026-12-31')));
        self::assertCount(1, $this->dayRates->findIn(BusinessPeriod::fromStrings('2026-12-25', '2026-12-31'), $anna));
        self::assertSame(['2026-12-31', '2026-12-24'], array_map(static fn ($rate): string => $rate->getDate()->toString(), $this->dayRates->findAll($anna)));

        // clear() detached the organization: the tenant context needs a managed one again to persist.
        $this->switchTo($this->entityManager->find(Organization::class, $this->tenantContext->getOrganization()->getId()) ?? self::fail('Organization expected.'));
        $this->expectException(PayRateDateTakenException::class);
        $this->dayRates->add(PayRateMother::day($anna, '2026-12-31'));
    }

    public function test_it_isolates_the_rates_of_each_organization(): void
    {
        $anna = Uuid::v7();
        $mine = OrganizationFactory::createOne();
        $theirs = OrganizationFactory::createOne();
        $this->switchTo($theirs);
        $theirRate = PayRateMother::hourly($anna, '2026-01-01', 9000);
        $theirDayRate = PayRateMother::day($anna, '2026-12-31', 9900);
        $this->rates->add($theirRate);
        $this->dayRates->add($theirDayRate);
        $this->switchTo($mine);
        // The same person and dates in another organization are no conflict.
        $this->rates->add(PayRateMother::hourly($anna, '2026-01-01', 3000));
        $this->dayRates->add(PayRateMother::day($anna, '2026-12-31', 4500));
        $this->entityManager->clear();

        self::assertNull($this->rates->findById($theirRate->getId()));
        self::assertNull($this->dayRates->findById($theirDayRate->getId()));
        self::assertSame([3000], array_map(static fn ($rate): int => $rate->getAmount(), $this->rates->findAll()));
        self::assertSame([4500], array_map(static fn ($rate): int => $rate->getAmount(), $this->dayRates->findAll()));

        $queryBus = self::getContainer()->get(QueryBusInterface::class);
        /** @var PayRateView|null $rate */
        $rate = $queryBus->ask(new GetPayRateQuery($anna->toRfc4122(), '2026-12-31'));
        self::assertSame([PayRateView::DAY, 4500], [$rate?->kind, $rate?->amount]);
        /** @var list<PayRateView> $rates */
        $rates = $queryBus->ask(new ListPayRatesQuery('2026-12-30', '2026-12-31'));
        self::assertSame([3000, 4500], array_map(static fn (PayRateView $view): int => $view->amount, $rates));

        $this->tenantContext->reset();
        $this->entityManager->clear();
        self::assertSame([], $this->rates->findAll(), 'No organization selected: no rows.');
        self::assertSame([], $this->dayRates->findAll());
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo($organization);
    }
}
