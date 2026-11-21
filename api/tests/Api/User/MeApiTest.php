<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Shared\Domain\Language\Language;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET and PATCH /api/me. User-level, not tenant data: no organization header, and each user only
 * ever reads and changes their own settings.
 */
final class MeApiTest extends ApiTestCase
{
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    public function test_it_returns_the_user_and_their_memberships(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Manager);

        static::clientFor($user)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'memberships' => [[
                'role' => 'manager',
                'organization' => [
                    'id' => $organization->getId()->toRfc4122(),
                    'name' => $organization->getName(),
                    'timezone' => $organization->getTimezone(),
                    'currency' => $organization->getCurrency(),
                ],
            ]],
        ]);
    }

    public function test_it_returns_each_organizations_time_zone(): void
    {
        $organization = OrganizationFactory::createOne(['timezone' => 'America/New_York']);
        $owner = ($organization->getMemberships()->first() ?: throw new \LogicException('Owner expected.'))->getUser();

        static::clientFor($owner)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'memberships' => [['organization' => ['id' => $organization->getId()->toRfc4122(), 'timezone' => 'America/New_York']]],
        ]);
    }

    public function test_it_returns_each_organizations_currency(): void
    {
        $organization = OrganizationFactory::createOne(['currency' => 'EUR']);
        $owner = ($organization->getMemberships()->first() ?: throw new \LogicException('Owner expected.'))->getUser();

        static::clientFor($owner)->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'memberships' => [['organization' => ['id' => $organization->getId()->toRfc4122(), 'currency' => 'EUR']]],
        ]);
    }

    public function test_it_returns_no_language_for_a_user_who_never_chose_one(): void
    {
        [$user] = self::memberWithRole(MembershipRole::Employee);

        $data = static::clientFor($user)->request('GET', '/api/me')->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('language', $data);
        self::assertNull($data['language']);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function roles(): iterable
    {
        foreach (MembershipRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('roles')]
    public function test_it_lets_every_user_change_their_own_language(MembershipRole $role): void
    {
        [$user] = self::memberWithRole($role);

        static::clientFor($user)->request('PATCH', '/api/me', ['json' => ['language' => 'pl'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['id' => $user->getId()->toRfc4122(), 'language' => 'pl']);
        self::assertSame(Language::Polish, self::reloaded($user)->getLanguage());
        self::assertSame('pl', static::clientFor($user)->request('GET', '/api/me')->toArray()['language']);
    }

    public function test_it_lets_a_user_without_an_organization_change_their_language(): void
    {
        $user = UserFactory::createOne();

        static::clientFor($user)->request('PATCH', '/api/me', ['json' => ['language' => 'en'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['language' => 'en', 'memberships' => []]);
    }

    public function test_it_changes_only_the_signed_in_users_language(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $colleague = UserFactory::createOne();

        static::clientFor($owner, $organization)->request('PATCH', '/api/me', ['json' => ['language' => 'pl'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertSame(Language::Polish, self::reloaded($owner)->getLanguage());
        self::assertNull(self::reloaded($colleague)->getLanguage());
    }

    public function test_it_clears_the_language_with_null(): void
    {
        $user = UserFactory::createOne();
        $user->changeLanguage(Language::Polish);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($user)->request('PATCH', '/api/me', ['json' => ['language' => null], 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['language' => null]);
        self::assertNull(self::reloaded($user)->getLanguage());
    }

    public function test_it_keeps_the_language_when_a_patch_does_not_send_it(): void
    {
        $user = UserFactory::createOne();
        $user->changeLanguage(Language::Polish);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($user)->request('PATCH', '/api/me', ['body' => '{}', 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['language' => 'pl']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLanguages(): iterable
    {
        yield 'unknown language' => ['de'];
        yield 'a locale rather than a language' => ['pl-PL'];
        yield 'upper case' => ['PL'];
        yield 'not a string' => [1];
    }

    #[DataProvider('invalidLanguages')]
    public function test_it_refuses_an_unknown_language(mixed $language): void
    {
        $user = UserFactory::createOne();

        $data = static::clientFor($user)
            ->request('PATCH', '/api/me', ['json' => ['language' => $language], 'headers' => self::MERGE_PATCH])
            ->toArray(throw: false);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('language', array_column($data['violations'] ?? [], 'propertyPath'));
        self::assertNull(self::reloaded($user)->getLanguage());
    }

    public function test_it_requires_authentication_to_change_the_language(): void
    {
        static::createClient()->request('PATCH', '/api/me', ['json' => ['language' => 'pl'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseStatusCodeSame(401);
    }

    private static function reloaded(User $user): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
    }
}
