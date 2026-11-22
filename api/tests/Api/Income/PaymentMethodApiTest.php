<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, PATCH, DELETE /payment-methods, POST /payment-methods/{id}/deactivate and
 * /reactivate, PUT /payment-methods/order (ADR 0030). The server's time is mocked at NOW (UTC),
 * already TODAY in the organizations' time zone, Europe/Warsaw.
 */
final class PaymentMethodApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-12 23:30:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_method_at_the_end_of_the_list(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::paymentMethodId($organization);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['name' => '  BLIK  ']);

        self::assertResponseStatusCodeSame(201);
        $method = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($method['id']));
        self::assertSame([
            'id' => $method['id'],
            'name' => 'BLIK',
            'active' => true,
            'cash' => false,
            'used' => false,
            'createdAt' => '2026-11-12T23:30:00+00:00',
            'deactivatedAt' => null,
        ], $method);
        self::assertSame($method, self::get($actor, $organization, $method['id'])->toArray());
        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher', 'BLIK'], array_column(self::list($actor, $organization)->toArray(), 'name'));
    }

    public function test_it_answers_409_for_a_name_another_method_has_even_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::deactivatePaymentMethod($organization, 'Voucher');
        self::mockTime(self::NOW);

        foreach (['Cash', ' Cash ', 'Voucher'] as $name) {
            $response = self::add($owner, $organization, ['name' => $name]);
            self::assertResponseStatusCodeSame(409, $name);
            self::assertSame(['income.payment_method_name_taken', ['name' => trim($name)]], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
        }

        self::add($owner, $organization, ['name' => 'cash']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_allows_the_same_name_in_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addPaymentMethod($elsewhere, 'BLIK');

        self::add($owner, $organization, ['name' => 'BLIK']);

        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNames(): iterable
    {
        yield 'no name' => [[]];
        yield from self::invalidRenames();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRenames(): iterable
    {
        yield 'null' => [['name' => null]];
        yield 'blank' => [['name' => '   ']];
        yield 'too long' => [['name' => str_repeat('a', 65)]];
        yield 'a number' => [['name' => 123]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNames')]
    public function test_it_answers_422_with_violations_for_an_invalid_new_method(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame([], self::list($owner, $organization, true)->toArray());
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_rename_a_method_and_entries_show_the_new_name(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $register = self::addRegister($organization);
        $id = self::paymentMethodId($organization, 'Card');
        self::mockTime(self::NOW);
        $entry = self::recordEntry($actor, $organization, $register->getId()->toRfc4122(), [self::pay($organization, 'Card', 500)])->toArray();

        $renamed = self::rename($actor, $organization, $id, ['name' => ' Card (terminal) '])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Card (terminal)', true, true], [$renamed['name'], $renamed['active'], $renamed['used']]);
        self::assertSame('Card (terminal)', self::getEntry($actor, $organization, $entry['id'])->toArray()['payments'][0]['paymentMethodName']);
        self::assertSame('Card', self::rename($actor, $organization, $id, ['name' => 'Card'])->toArray()['name']);
        self::assertSame('Card', self::rename($actor, $organization, $id, [])->toArray()['name']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_keep_at_most_one_active_method_marked_as_cash(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $cash = self::paymentMethodId($organization, 'Cash');
        $card = self::paymentMethodId($organization, 'Card');
        self::assertSame([true, false, false, false], array_column(self::list($actor, $organization)->toArray(), 'cash'));

        $response = self::add($actor, $organization, ['name' => 'BLIK', 'cash' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('income.cash_payment_method_taken', $response->toArray(false)['code']);
        self::assertSame('BLIK', $response->toArray(false)['parameters']['name']);

        foreach ([['cash' => true], ['name' => 'Card (cash)', 'cash' => true]] as $body) {
            $response = self::rename($actor, $organization, $card, $body);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(['income.cash_payment_method_taken', ['paymentMethodId' => $card, 'name' => $body['name'] ?? 'Card']], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
        }
        self::assertSame(['Card', false], [self::get($actor, $organization, $card)->toArray()['name'], self::get($actor, $organization, $card)->toArray()['cash']]);
        self::assertCount(4, self::list($actor, $organization, true)->toArray());

        // Moving the mark: remove it first, then mark the other method.
        self::assertFalse(self::rename($actor, $organization, $cash, ['cash' => false])->toArray()['cash']);
        $marked = self::rename($actor, $organization, $card, ['cash' => true])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Card', true], [$marked['name'], $marked['cash']]);
        self::assertTrue(self::rename($actor, $organization, $card, ['name' => 'Karta'])->toArray()['cash']);

        // A deactivated cash method keeps its mark, and comes back only while no other active method is cash.
        self::assertTrue(self::deactivate($actor, $organization, $card)->toArray()['cash']);
        self::assertTrue(self::rename($actor, $organization, $cash, ['cash' => true])->toArray()['cash']);
        $response = self::reactivate($actor, $organization, $card);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['income.cash_payment_method_taken', ['paymentMethodId' => $card, 'name' => 'Karta']], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
        self::assertFalse(self::get($actor, $organization, $card)->toArray()['active']);

        self::rename($actor, $organization, $cash, ['cash' => false]);
        self::assertSame([true, true], [self::reactivate($actor, $organization, $card)->toArray()['active'], self::get($actor, $organization, $card)->toArray()['cash']]);
        self::assertSame('BLIK', self::add($actor, $organization, ['name' => 'BLIK', 'cash' => false])->toArray()['name']);
    }

    public function test_it_counts_only_the_organizations_own_cash_method(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::paymentMethodId($elsewhere);
        $mine = self::addPaymentMethod($organization, 'Gotówka')->getId()->toRfc4122();

        $marked = self::rename($owner, $organization, $mine, ['cash' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($marked->toArray()['cash']);
    }

    public function test_it_answers_422_for_a_cash_flag_that_is_not_a_boolean(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $card = self::paymentMethodId($organization, 'Card');

        $responses = [
            self::add($owner, $organization, ['name' => 'BLIK', 'cash' => 'yes']),
            self::rename($owner, $organization, $card, ['cash' => null]),
            self::rename($owner, $organization, $card, ['cash' => 'yes']),
        ];

        foreach ($responses as $response) {
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['cash'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        }
        self::assertSame([true, false], array_slice(array_column(self::list($owner, $organization, true)->toArray(), 'cash'), 0, 2));
        self::assertCount(4, self::list($owner, $organization, true)->toArray());
    }

    public function test_it_answers_409_and_422_for_an_invalid_rename(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::paymentMethodId($organization, 'Card');

        self::rename($owner, $organization, $id, ['name' => 'Cash']);
        self::assertResponseStatusCodeSame(409);

        foreach (self::invalidRenames() as [$body]) {
            $response = self::rename($owner, $organization, $id, $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        }
        self::assertSame('Card', self::get($owner, $organization, $id)->toArray()['name']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_deactivate_and_reactivate_a_method(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $card = self::paymentMethodId($organization, 'Card');
        self::mockTime(self::NOW);

        $deactivated = self::deactivate($actor, $organization, $card)->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, '2026-11-12T23:30:00+00:00'], [$deactivated['active'], $deactivated['deactivatedAt']]);
        self::assertSame($deactivated, self::deactivate($actor, $organization, $card)->toArray());
        self::assertSame(['Cash', 'Transfer', 'Voucher'], array_column(self::list($actor, $organization)->toArray(), 'name'));
        self::assertSame(self::list($actor, $organization)->toArray(), self::list($actor, $organization, false)->toArray());
        self::assertSame(['Cash', 'Transfer', 'Voucher', 'Card'], array_column(self::list($actor, $organization, true)->toArray(), 'name'));

        $reactivated = self::reactivate($actor, $organization, $card)->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, null], [$reactivated['active'], $reactivated['deactivatedAt']]);
        self::assertSame($reactivated, self::reactivate($actor, $organization, $card)->toArray());
        self::assertSame(['Cash', 'Transfer', 'Voucher', 'Card'], array_column(self::list($actor, $organization)->toArray(), 'name'));
    }

    #[DataProvider('managingRoles')]
    public function test_it_deletes_an_unused_method_and_answers_409_for_a_used_one(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        self::recordEntry($actor, $organization, $register->getId()->toRfc4122(), [self::pay($organization, 'Cash', 500)]);
        $cash = self::paymentMethodId($organization, 'Cash');
        $transfer = self::paymentMethodId($organization, 'Transfer');

        self::assertSame([true, false, false, false], array_column(self::list($actor, $organization)->toArray(), 'used'));

        $response = self::delete($actor, $organization, $transfer);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::get($actor, $organization, $transfer);
        self::assertResponseStatusCodeSame(404);

        $response = self::delete($actor, $organization, $cash);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['income.payment_method_in_use', ['paymentMethodId' => $cash, 'name' => 'Cash']], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
        self::assertSame(['Cash', 'Card', 'Voucher'], array_column(self::list($actor, $organization)->toArray(), 'name'));
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_reorder_the_active_methods(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $register = self::addRegister($organization);
        $ids = array_map(static fn (string $name): string => self::paymentMethodId($organization, $name), ['Cash', 'Card', 'Transfer', 'Voucher']);
        self::deactivatePaymentMethod($organization, 'Transfer');
        self::mockTime(self::NOW);

        $response = self::reorder($actor, $organization, [$ids[3], $ids[1], $ids[0]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame(['Voucher', 'Card', 'Cash', 'Transfer'], array_column(self::list($actor, $organization, true)->toArray(), 'name'));
        $entry = self::recordEntry($actor, $organization, $register->getId()->toRfc4122(), [self::pay($organization, 'Cash', 1), self::pay($organization, 'Voucher', 2)])->toArray();
        self::assertSame(['Voucher', 'Cash'], array_column($entry['payments'], 'paymentMethodName'));
    }

    public function test_it_answers_409_for_an_order_that_is_not_exactly_the_active_methods(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $ids = array_map(static fn (string $name): string => self::paymentMethodId($organization, $name), ['Cash', 'Card', 'Transfer', 'Voucher']);

        foreach ([
            'one missing' => [$ids[0], $ids[1], $ids[2]],
            'another organization\'s' => [$ids[0], $ids[1], $ids[2], self::paymentMethodId($elsewhere, 'Cash')],
            'one extra' => [...$ids, Uuid::v7()->toRfc4122()],
        ] as $case => $order) {
            $response = self::reorder($owner, $organization, $order);
            self::assertResponseStatusCodeSame(409, $case);
            self::assertSame('income.payment_method_order_mismatch', $response->toArray(false)['code']);
        }
        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher'], array_column(self::list($owner, $organization)->toArray(), 'name'));
    }

    public function test_it_answers_422_for_a_malformed_order(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::paymentMethodId($organization, 'Cash');

        foreach ([
            'empty' => [['paymentMethodIds' => []], 'paymentMethodIds'],
            'missing' => [[], 'paymentMethodIds'],
            'a duplicate' => [['paymentMethodIds' => [$cash, $cash]], 'paymentMethodIds'],
            'not a UUID' => [['paymentMethodIds' => ['cash']], 'paymentMethodIds[0]'],
        ] as $case => [$body, $path]) {
            $response = static::clientFor($owner, $organization)->request('PUT', '/api/payment-methods/order', ['json' => $body]);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame([$path], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))), $case);
        }
    }

    public function test_it_answers_404_for_an_unknown_method(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        foreach (self::requestsById() as $case => [$request]) {
            $request($owner, $organization, $unknown);
            self::assertResponseStatusCodeSame(404, $case);
        }
        static::clientFor($owner, $organization)->request('GET', '/api/payment-methods/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_methods(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::paymentMethodId($organization);
        $id = self::addPaymentMethod($elsewhere, 'BLIK')->getId()->toRfc4122();
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher'], array_column(self::list($owner, $organization, true)->toArray(), 'name'));
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
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::rename($user, $organization, $id, ['name' => 'Mine now'])];
        yield 'PATCH cash' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::rename($user, $organization, $id, ['cash' => true])];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
        yield 'reactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reactivate($user, $organization, $id)];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, true)];
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'BLIK'])];
        yield 'POST cash' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'Cash 2', 'cash' => true])];
        yield 'PUT order' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reorder($user, $organization, [$id])];
        yield from self::requestsById();
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_methods_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = self::addPaymentMethod($organization, 'BLIK')->getId()->toRfc4122();
        self::mockTime(self::NOW);
        $before = self::list($owner, $organization, true)->toArray();

        foreach ([$employee, $formerManager] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }
        // Before looking at the method: an unknown one is a 403 too.
        $request($employee, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);

        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    /** @param ?bool $includeDeactivated null leaves the parameter out */
    private static function list(User $user, Organization $organization, ?bool $includeDeactivated = null): ResponseInterface
    {
        $query = null === $includeDeactivated ? '' : '?includeDeactivated='.($includeDeactivated ? 'true' : 'false');

        return static::clientFor($user, $organization)->request('GET', '/api/payment-methods'.$query);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/payment-methods/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/payment-methods', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function rename(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/payment-methods/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }

    private static function deactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/payment-methods/'.$id.'/deactivate');
    }

    private static function reactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/payment-methods/'.$id.'/reactivate');
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/payment-methods/'.$id);
    }

    /** @param list<string> $ids */
    private static function reorder(User $user, Organization $organization, array $ids): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PUT', '/api/payment-methods/order', ['json' => ['paymentMethodIds' => $ids]]);
    }

    /** @param list<array{paymentMethodId: string, amount: int}> $payments */
    private static function recordEntry(User $user, Organization $organization, string $registerId, array $payments): ResponseInterface
    {
        $response = static::clientFor($user, $organization)->request('POST', '/api/revenue-entries', ['json' => [
            'registerId' => $registerId,
            'businessDate' => '2026-11-12',
            'payments' => $payments,
        ]]);
        self::assertResponseStatusCodeSame(201);

        return $response;
    }

    private static function getEntry(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries/'.$id);
    }
}
