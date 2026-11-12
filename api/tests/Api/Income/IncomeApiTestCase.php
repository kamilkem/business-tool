<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Arranges income data for API tests. Registers are stored through the repository in the
 * organization's tenant context, until they have endpoints of their own (#108).
 */
abstract class IncomeApiTestCase extends ApiTestCase
{
    protected static function addRegister(Organization $organization, string $name = 'Bar'): Register
    {
        $owner = OrganizationMother::ownerOf($organization);
        $register = Register::create(Uuid::v7(), $name, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        self::inTenant($organization, static fn () => static::getContainer()->get(RegisterRepositoryInterface::class)->add($register));

        return $register;
    }

    protected static function deactivateRegister(Organization $organization, Register $register): void
    {
        $owner = OrganizationMother::ownerOf($organization);

        self::inTenant($organization, static function () use ($register, $owner): void {
            $registers = static::getContainer()->get(RegisterRepositoryInterface::class);
            $managed = $registers->findById($register->getId()) ?? throw new \LogicException('Register expected.');
            $managed->deactivate($owner->getId(), MembershipRole::Owner, Clock::get()->now());
            $registers->save($managed);
        });
    }

    protected static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function inTenant(Organization $organization, \Closure $action): void
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
