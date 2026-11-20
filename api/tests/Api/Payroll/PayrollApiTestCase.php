<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PayRateMother;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Arranges members, rates and locked months in an organization's tenant, as the Payroll API tests share them. */
abstract class PayrollApiTestCase extends ApiTestCase
{
    protected const string NOW = '2026-11-20 10:00:00';
    protected const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    protected static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    protected static function addHourlyRate(Organization $organization, User $user, string $effectiveFrom, int $amount = 3000, string $currency = 'PLN'): HourlyRate
    {
        $rate = PayRateMother::hourly($user->getId(), $effectiveFrom, $amount, $currency);
        self::inTenant($organization, static fn () => static::getContainer()->get(HourlyRateRepositoryInterface::class)->add($rate));

        return $rate;
    }

    protected static function addDayRate(Organization $organization, User $user, string $date, int $amount = 4500): DayRate
    {
        $dayRate = PayRateMother::day($user->getId(), $date, $amount);
        self::inTenant($organization, static fn () => static::getContainer()->get(DayRateRepositoryInterface::class)->add($dayRate));

        return $dayRate;
    }

    /** Locks the dates as an approved payroll run will (#62): through Time tracking's command. */
    protected static function lock(Organization $organization, string $firstDate, string $lastDate): void
    {
        self::inTenant($organization, static fn () => static::getContainer()->get(CommandBusInterface::class)->handle(new LockShiftPeriodCommand(
            $organization->getId()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            $firstDate,
            $lastDate,
        )));
    }

    protected static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            // A request starts with an empty identity map: an arranged entity must not be found by
            // id there without the TenantFilter (EntityManager::find() reads the map first).
            $entityManager->clear();
        }
    }
}
