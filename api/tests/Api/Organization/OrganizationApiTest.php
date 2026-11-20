<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

final class OrganizationApiTest extends ApiTestCase
{
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    public function test_it_lists_only_the_users_organizations(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        OrganizationFactory::createMany(2);

        $data = static::clientFor($user)->request('GET', '/api/organizations')->toArray();

        self::assertSame([$organization->getId()->toRfc4122()], array_column($data, 'id'));
    }

    public function test_it_returns_an_organization_to_a_member(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);

        static::clientFor($user)->request('GET', '/api/organizations/'.$organization->getId());

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => $organization->getName(), 'taxId' => null, 'currency' => 'PLN']);
    }

    public function test_it_answers_404_for_another_users_organization(): void
    {
        $other = OrganizationFactory::createOne();

        static::clientFor(UserFactory::createOne())->request('GET', '/api/organizations/'.$other->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_creates_an_organization_owned_by_the_caller(): void
    {
        $user = UserFactory::createOne();

        $data = static::clientFor($user)->request('POST', '/api/organizations', [
            'json' => ['name' => 'Corner Pub', 'taxId' => '1234567890'],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Corner Pub', '1234567890', 'PL', 'PLN', 'semicolon'], [$data['name'], $data['taxId'], $data['countryCode'], $data['currency'], $data['csvSeparator']]);
        $membership = static::getContainer()->get(MembershipRepositoryInterface::class)
            ->findOne($user->getId(), Uuid::fromString($data['id']));
        self::assertSame(MembershipRole::Owner, $membership?->getRole());
    }

    public function test_it_requires_a_name_on_create(): void
    {
        static::clientFor(UserFactory::createOne())->request('POST', '/api/organizations', ['json' => []]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_validates_settings_on_create(): void
    {
        static::clientFor(UserFactory::createOne())->request('POST', '/api/organizations', [
            'json' => ['name' => 'Corner Pub', 'currency' => 'XXZ'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'currency']]]);
    }

    public function test_it_only_changes_the_fields_sent_in_a_patch(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($user)->request('PATCH', '/api/organizations/'.$organization->getId(), [
            'json' => ['taxId' => '1234567890'],
            'headers' => self::MERGE_PATCH,
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => $organization->getName(), 'taxId' => '1234567890', 'currency' => 'PLN']);
    }

    public function test_it_defaults_the_csv_separator_from_the_country_takes_one_on_create_and_changes_it(): void
    {
        $user = UserFactory::createOne();
        $create = static fn (array $body): array => static::clientFor($user)->request('POST', '/api/organizations', ['json' => ['name' => 'Diner'] + $body])->toArray();

        self::assertSame('comma', $create(['countryCode' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York'])['csvSeparator']);
        $organization = $create(['csvSeparator' => 'comma']);
        self::assertSame(['PL', 'comma'], [$organization['countryCode'], $organization['csvSeparator']]);

        static::clientFor($user)->request('PATCH', '/api/organizations/'.$organization['id'], ['json' => ['csvSeparator' => 'semicolon'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['csvSeparator' => 'semicolon', 'name' => 'Diner']);

        static::clientFor($user)->request('PATCH', '/api/organizations/'.$organization['id'], ['json' => ['csvSeparator' => 'tab'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string, array{MembershipRole|null, int}> */
    public static function updateAuthorization(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200];
        yield 'manager' => [MembershipRole::Manager, 200];
        yield 'employee' => [MembershipRole::Employee, 403];
        yield 'non-member' => [null, 404];
    }

    #[DataProvider('updateAuthorization')]
    public function test_it_authorizes_updates_by_role(?MembershipRole $role, int $expectedStatus): void
    {
        if (null === $role) {
            $user = UserFactory::createOne();
            $organization = OrganizationFactory::createOne();
        } else {
            [$user, $organization] = self::memberWithRole($role);
        }

        static::clientFor($user)->request('PATCH', '/api/organizations/'.$organization->getId(), [
            'json' => ['name' => 'Renamed'],
            'headers' => self::MERGE_PATCH,
        ]);

        self::assertResponseStatusCodeSame($expectedStatus);
    }
}
