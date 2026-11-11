<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase as BaseApiTestCase;
use ApiPlatform\Test\Client;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Ui\Http\Listener\SelectOrganizationFromHeaderListener;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * self::mockTime() (ClockSensitiveTrait) freezes or moves the global clock, which the `clock` service,
 * the Foundry factories and the mothers read; it is restored after each test.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = false;

    /** A client authenticated as the user, optionally with an organization selected. */
    protected static function clientFor(User $user, ?Organization $organization = null): Client
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        if (null !== $organization) {
            $headers[SelectOrganizationFromHeaderListener::HEADER] = $organization->getId()->toRfc4122();
        }

        return static::createClient(defaultOptions: ['headers' => $headers]);
    }

    /** @return array{User, Organization} a new user with the given role in a new organization */
    protected static function memberWithRole(MembershipRole $role): array
    {
        $organization = OrganizationFactory::createOne();
        if (MembershipRole::Owner === $role) {
            $owner = $organization->getMemberships()->first() ?: throw new \LogicException('Owner expected.');

            return [$owner->getUser(), $organization];
        }

        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return [$user, $organization];
    }
}
