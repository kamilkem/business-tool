<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/** GET /vat-rates: the rates of the selected organization's country (ADR 0022 §6). */
final class VatRateApiTest extends IncomeApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_rates_of_a_polish_organization(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);

        $rates = static::clientFor($user, $organization)->request('GET', '/api/vat-rates')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['code' => '23', 'zeroVat' => false],
            ['code' => '8', 'zeroVat' => false],
            ['code' => '5', 'zeroVat' => false],
            ['code' => '0', 'zeroVat' => true],
            ['code' => 'zw', 'zeroVat' => true],
        ], $rates);
    }

    public function test_it_lists_no_rates_for_a_country_without_them(): void
    {
        $organization = OrganizationFactory::createOne(['countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        $rates = static::clientFor(OrganizationMother::ownerOf($organization), $organization)->request('GET', '/api/vat-rates')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $rates);
    }

    public function test_it_refuses_the_rates_to_employees_and_deactivated_members(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([$employee, $formerManager] as $user) {
            static::clientFor($user, $organization)->request('GET', '/api/vat-rates');
            self::assertResponseStatusCodeSame(403);
        }
    }
}
