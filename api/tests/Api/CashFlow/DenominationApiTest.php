<?php

declare(strict_types=1);

namespace App\Tests\Api\CashFlow;

use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, DELETE /denominations and POST /denominations/{id}/deactivate and /reactivate (ADR 0033
 * §2). The organizations are in PLN; the server's time is mocked at NOW (UTC).
 */
final class DenominationApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_denomination_in_the_organizations_currency(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::addDenomination($organization, 1_000);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['value' => 500, 'kind' => 'coin']);

        self::assertResponseStatusCodeSame(201);
        $denomination = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($denomination['id']));
        self::assertSame([
            'id' => $denomination['id'],
            'currency' => 'PLN',
            'value' => 500,
            'kind' => 'coin',
            'active' => true,
            'used' => false,
            'createdAt' => '2026-11-22T10:00:00+00:00',
            'deactivatedAt' => null,
        ], $denomination);
        self::assertSame($denomination, self::get($actor, $organization, $denomination['id'])->toArray());
        self::assertSame([500, 1_000], array_column(self::list($actor, $organization)->toArray(), 'value'));
    }

    public function test_it_answers_409_for_a_value_the_organization_has_even_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();
        self::deactivate($owner, $organization, $id);

        $response = self::add($owner, $organization, ['value' => 500, 'kind' => 'note']);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('cash_flow.denomination_value_taken', $problem['code']);
        self::assertSame(['currency' => 'PLN', 'value' => 500], $problem['parameters']);
    }

    public function test_it_allows_the_same_value_in_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addDenomination($elsewhere, 500);

        self::add($owner, $organization, ['value' => 500, 'kind' => 'coin']);

        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [[], ['kind', 'value']];
        yield 'zero' => [['value' => 0, 'kind' => 'coin'], ['value']];
        yield 'negative' => [['value' => -5, 'kind' => 'coin'], ['value']];
        yield 'too large' => [['value' => Denomination::MAX_VALUE + 1, 'kind' => 'coin'], ['value']];
        yield 'a string value' => [['value' => '500', 'kind' => 'coin'], ['value']];
        yield 'a float value' => [['value' => 5.5, 'kind' => 'coin'], ['value']];
        yield 'an unknown kind' => [['value' => 500, 'kind' => 'banknote'], ['kind']];
        yield 'a blank kind' => [['value' => 500, 'kind' => ''], ['kind']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidBodies')]
    public function test_it_answers_422_with_violations_for_an_invalid_denomination(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        $found = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($found);
        self::assertSame($paths, $found);
        self::assertSame([], self::list($owner, $organization, true)->toArray());
    }

    public function test_it_translates_violations_into_polish(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('POST', '/api/denominations', [
            'json' => ['value' => 0, 'kind' => 'coin'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $message = $response->toArray(false)['violations'][0]['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('powinna', $message);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_deactivate_and_reactivate_a_denomination(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();
        self::addDenomination($organization, 100);
        self::mockTime(self::NOW);

        $deactivated = self::deactivate($actor, $organization, $id);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($deactivated->toArray()['active']);
        self::assertSame('2026-11-22T10:00:00+00:00', $deactivated->toArray()['deactivatedAt']);
        self::assertSame([100], array_column(self::list($actor, $organization)->toArray(), 'value'));
        self::assertSame([100, 500], array_column(self::list($actor, $organization, true)->toArray(), 'value'));

        $reactivated = self::reactivate($actor, $organization, $id);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($reactivated->toArray()['active']);
        self::assertNull($reactivated->toArray()['deactivatedAt']);
        self::assertSame([100, 500], array_column(self::list($actor, $organization)->toArray(), 'value'));
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_delete_an_unused_denomination(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();

        self::delete($actor, $organization, $id);

        self::assertResponseStatusCodeSame(204);
        self::get($actor, $organization, $id);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_lets_employees_read_the_denominations_for_the_close(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();

        self::assertSame([500], array_column(self::list($employee, $organization)->toArray(), 'value'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(500, self::get($employee, $organization, $id)->toArray()['value']);
    }

    public function test_it_lists_only_the_organizations_current_currency(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addDenomination($organization, 500);
        self::addDenomination($organization, 200, 'EUR');

        self::assertSame([['PLN', 500]], array_map(
            static fn (array $d): array => [$d['currency'], $d['value']],
            self::list($owner, $organization, true)->toArray(),
        ));
    }

    public function test_it_answers_404_for_an_unknown_denomination(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = Uuid::v7()->toRfc4122();

        foreach (self::requestsById() as $case => [$request]) {
            $response = $request($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404, $case);
            if ('GET' !== $case) {
                self::assertSame('cash_flow.denomination_not_found', $response->toArray(false)['code'], $case);
            }
        }
    }

    public function test_it_hides_another_organizations_denominations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addDenomination($organization, 100);
        $id = self::addDenomination($elsewhere, 500)->getId()->toRfc4122();
        // A member of both: what counts is the organization selected by the header.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        self::assertSame([100], array_column(self::list($owner, $organization, true)->toArray(), 'value'));
        foreach (self::requestsById() as $case => [$request]) {
            $request($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404, $case);
        }

        self::assertSame($before, self::get($ownerElsewhere, $elsewhere, $id)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requestsById(): iterable
    {
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
        yield 'reactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reactivate($user, $organization, $id)];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function writes(): iterable
    {
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['value' => 200, 'kind' => 'coin'])];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
        yield 'reactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reactivate($user, $organization, $id)];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('writes')]
    public function test_it_refuses_changes_to_employees_with_a_code(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();
        $before = self::list($owner, $organization, true)->toArray();

        $response = $request($employee, $organization, $id);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('cash_flow.access_forbidden', $response->toArray(false)['code']);
        // Before looking at the denomination: an unknown one is a 403 too.
        $request($employee, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, true)];
        yield from self::writes();
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_deactivated_members_and_outsiders(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $outsider = UserFactory::createOne();
        $id = self::addDenomination($organization, 500)->getId()->toRfc4122();
        $before = self::list($owner, $organization, true)->toArray();

        foreach ([$formerManager, $outsider] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    private static function addDenomination(Organization $organization, int $value, string $currency = 'PLN'): Denomination
    {
        $owner = OrganizationMother::ownerOf($organization);
        $denomination = Denomination::create(Uuid::v7(), $currency, $value, $value >= 1_000 ? DenominationKind::Note : DenominationKind::Coin, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            static::getContainer()->get(DenominationRepositoryInterface::class)->add($denomination);
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }

        return $denomination;
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function list(User $user, Organization $organization, bool $includeDeactivated = false): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/denominations'.($includeDeactivated ? '?includeDeactivated=true' : ''));
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/denominations/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/denominations', ['json' => $body]);
    }

    private static function deactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/denominations/'.$id.'/deactivate');
    }

    private static function reactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/denominations/'.$id.'/reactivate');
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/denominations/'.$id);
    }
}
