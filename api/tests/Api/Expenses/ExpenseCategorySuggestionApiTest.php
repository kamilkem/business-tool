<?php

declare(strict_types=1);

namespace App\Tests\Api\Expenses;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /expense-category-suggestion and the `categorySuggested` mark of GET and PATCH /expenses/{id}
 * (#55), in organizations of the factories (PL, PLN).
 */
final class ExpenseCategorySuggestionApiTest extends ExpensesApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_suggests_the_category_used_most_often_for_the_seller(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $food = self::addCategory($organization, 'Food');
        $beverages = self::addCategory($organization, 'Beverages');
        self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerTaxId: '5265877635'), category: $food);
        self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerTaxId: '5265877635'), category: $beverages);
        self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerTaxId: '5265877635'), category: $beverages);
        self::addInvoice($organization, PurchaseInvoiceMother::manual(sellerName: 'Targ Rybny'), category: $food);

        self::assertSame(
            ['categoryId' => $beverages->getId()->toRfc4122(), 'categoryName' => 'Beverages'],
            self::suggest($actor, $organization, ['sellerTaxId' => '526-587-76-35', 'sellerName' => 'Targ Rybny'])->toArray(),
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['categoryId' => $food->getId()->toRfc4122(), 'categoryName' => 'Food'],
            self::suggest($actor, $organization, ['sellerName' => ' TARG RYBNY'])->toArray(),
        );
    }

    public function test_it_suggests_nothing_for_a_new_seller_or_none(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addInvoice($organization, PurchaseInvoiceMother::imported(), category: self::addCategory($organization, 'Food'));

        foreach ([['sellerTaxId' => '9999999999'], ['sellerName' => 'Nowy'], []] as $query) {
            self::assertSame(['categoryId' => null, 'categoryName' => null], self::suggest($owner, $organization, $query)->toArray());
        }
    }

    public function test_it_answers_422_for_too_long_parameters(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::suggest($owner, $organization, ['sellerTaxId' => str_repeat('1', 33), 'sellerName' => str_repeat('a', 513)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sellerTaxId', 'sellerName'], array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function test_it_suggests_nothing_from_another_organizations_history(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addInvoice($elsewhere, PurchaseInvoiceMother::imported(sellerTaxId: '5265877635'), category: self::addCategory($elsewhere, 'Food'));
        // A member elsewhere too: the selected organization decides whose history counts.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Manager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(['categoryId' => null, 'categoryName' => null], self::suggest($owner, $organization, ['sellerTaxId' => '5265877635'])->toArray());
    }

    public function test_it_refuses_employees_deactivated_members_and_strangers(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$stranger] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([$employee, $formerManager, $stranger] as $user) {
            self::suggest($user, $organization, ['sellerTaxId' => '5265877635']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_it_shows_a_suggested_category_until_a_patch_confirms_it(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $food = self::addCategory($organization, 'Food');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported(), category: $food, suggested: true)->getId()->toRfc4122();

        $expense = self::get($owner, $organization, $id)->toArray();
        self::assertSame([$food->getId()->toRfc4122(), 'Food', true], [$expense['categoryId'], $expense['categoryName'], $expense['categorySuggested']]);
        $listed = static::clientFor($owner, $organization)->request('GET', '/api/expenses?from=2026-11-01&to=2026-11-30&uncategorized=true')->toArray();
        self::assertSame([], $listed['items']);

        $confirmed = static::clientFor($owner, $organization)->request('PATCH', '/api/expenses/'.$id, [
            'json' => ['categoryId' => $food->getId()->toRfc4122()],
            'headers' => self::MERGE_PATCH,
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$food->getId()->toRfc4122(), false], [$confirmed['categoryId'], $confirmed['categorySuggested']]);
        self::assertFalse(self::get($owner, $organization, $id)->toArray()['categorySuggested']);
    }

    /** @param array<string, string> $query */
    private static function suggest(User $user, Organization $organization, array $query): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expense-category-suggestion', ['query' => $query]);
    }
}
