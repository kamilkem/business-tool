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
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST /expenses, PATCH and DELETE /expenses/{id} for expenses entered by hand (#54), in
 * organizations of the factories (PL, PLN); imported ones come from PurchaseInvoiceMother.
 */
final class ManualExpenseApiTest extends ExpensesApiTestCase
{
    private const string NOW = '2026-11-20 10:00:00';

    private const array RECEIPT = [
        'sellerName' => 'Targ Rybny',
        'issueDate' => '2026-11-14',
        'netTotal' => 5_000,
        'vatTotal' => 400,
        'grossTotal' => 5_400,
        'vatLines' => [['rate' => '8', 'net' => 5_000, 'vat' => 400]],
    ];

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_enters_a_receipt_in_the_organizations_currency(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $food = self::addCategory($organization, 'Food');

        $expense = self::enter($actor, $organization, [...self::RECEIPT, 'categoryId' => $food->getId()->toRfc4122()])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertTrue(Uuid::isValid($expense['id']));
        self::assertSame([
            'id' => $expense['id'],
            'source' => 'manual',
            'externalId' => null,
            'invoiceNumber' => null,
            'sellerTaxId' => null,
            'sellerName' => 'Targ Rybny',
            'issueDate' => '2026-11-14',
            'kind' => 'regular',
            'correctedExternalId' => null,
            'currency' => 'PLN',
            'taxCurrency' => 'PLN',
            'netTotal' => 5_000,
            'vatTotal' => 400,
            'grossTotal' => 5_400,
            'vatLines' => [['rate' => '8', 'net' => 5_000, 'vat' => 400, 'vatInTaxCurrency' => null]],
            'categoryId' => $food->getId()->toRfc4122(),
            'categoryName' => 'Food',
            'categorySuggested' => false,
            'hasDocument' => false,
            'documentFormat' => null,
            'createdAt' => '2026-11-20T10:00:00+00:00',
        ], $expense);
        self::assertSame($expense, self::get($actor, $organization, $expense['id'])->toArray());
    }

    public function test_it_lists_a_manual_expense_with_the_imported_ones(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $imported = self::addInvoice($organization, PurchaseInvoiceMother::imported(issueDate: '2026-11-10'));
        $manual = self::enter($owner, $organization, self::RECEIPT)->toArray();

        $list = self::list($owner, $organization)->toArray();

        self::assertSame([$manual['id'], $imported->getId()->toRfc4122()], array_column($list['items'], 'id'));
        self::assertSame(['manual', 'ksef'], array_column($list['items'], 'source'));
        self::assertSame(2, $list['totals'][0]['invoiceCount']);
        self::assertSame(24_600 + 5_400, $list['totals'][0]['grossTotal']);
    }

    public function test_it_enters_a_foreign_invoice_with_its_number_seller_tax_id_and_vat_in_the_organizations_currency(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $expense = self::enter($owner, $organization, [
            'sellerName' => 'Weingut Müller',
            'sellerTaxId' => 'DE123456789',
            'invoiceNumber' => 'R-2026-118',
            'issueDate' => '2026-11-03',
            'kind' => 'simplified',
            'currency' => 'EUR',
            'netTotal' => 10_000,
            'vatTotal' => 2_300,
            'grossTotal' => 12_300,
            'vatLines' => [['rate' => '23', 'net' => 10_000, 'vat' => 2_300, 'vatInTaxCurrency' => 9_800]],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['R-2026-118', 'DE123456789', 'simplified', 'EUR', 'PLN'], [$expense['invoiceNumber'], $expense['sellerTaxId'], $expense['kind'], $expense['currency'], $expense['taxCurrency']]);
        self::assertSame([['rate' => '23', 'net' => 10_000, 'vat' => 2_300, 'vatInTaxCurrency' => 9_800]], $expense['vatLines']);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidEntries(): iterable
    {
        $without = static function (string $field): array {
            $body = self::RECEIPT;
            unset($body[$field]);

            return $body;
        };

        yield 'no seller name' => [$without('sellerName'), ['sellerName']];
        yield 'blank seller name' => [[...self::RECEIPT, 'sellerName' => '  '], ['sellerName']];
        yield 'no issue date' => [$without('issueDate'), ['issueDate']];
        yield 'not a date' => [[...self::RECEIPT, 'issueDate' => '2026-02-30'], ['issueDate']];
        yield 'no gross total' => [$without('grossTotal'), ['grossTotal']];
        yield 'a fraction' => [[...self::RECEIPT, 'netTotal' => 50.5], ['netTotal']];
        yield 'a string amount' => [[...self::RECEIPT, 'vatTotal' => '400'], ['vatTotal']];
        yield 'unknown kind' => [[...self::RECEIPT, 'kind' => 'receipt'], ['kind']];
        yield 'not a currency' => [[...self::RECEIPT, 'currency' => 'ZLT'], ['currency']];
        yield 'seller tax ID too long' => [[...self::RECEIPT, 'sellerTaxId' => str_repeat('1', 33)], ['sellerTaxId']];
        yield 'a rate twice' => [[...self::RECEIPT, 'vatLines' => [['rate' => '8', 'net' => 1, 'vat' => 0], ['rate' => '8', 'net' => 1, 'vat' => 0]]], ['vatLines']];
        yield 'a line without VAT' => [[...self::RECEIPT, 'vatLines' => [['rate' => '8', 'net' => 1]]], ['vatLines[0].vat']];
        yield 'category not a UUID' => [[...self::RECEIPT, 'categoryId' => 'food'], ['categoryId']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidEntries')]
    public function test_it_answers_422_with_violations_for_an_invalid_entry(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::enter($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame([], self::list($owner, $organization)->toArray()['items']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function entriesTheDomainRefuses(): iterable
    {
        yield 'a rate of another country' => [[...self::RECEIPT, 'vatLines' => [['rate' => '19', 'net' => 100, 'vat' => 19]]], 'is not a VAT rate'];
        yield 'VAT at the exempt rate' => [[...self::RECEIPT, 'vatLines' => [['rate' => 'zw', 'net' => 100, 'vat' => 1]]], 'carries no VAT'];
        yield 'another currency without VAT in PLN' => [[...self::RECEIPT, 'currency' => 'EUR'], 'must also be given in PLN'];
        yield 'own currency with VAT in PLN' => [[...self::RECEIPT, 'vatLines' => [['rate' => '8', 'net' => 5_000, 'vat' => 400, 'vatInTaxCurrency' => 400]]], 'already in it'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('entriesTheDomainRefuses')]
    public function test_it_answers_422_for_vat_lines_the_domain_refuses(array $body, string $message): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::enter($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $response->getContent(false));
        self::assertSame([], self::list($owner, $organization)->toArray()['items']);
    }

    public function test_it_refuses_a_category_of_another_organization_or_an_archived_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Food');
        $archived = self::addCategory($organization, 'Old rent');
        self::archiveCategory($organization, $archived);

        self::enter($owner, $organization, [...self::RECEIPT, 'categoryId' => $foreign->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::enter($owner, $organization, [...self::RECEIPT, 'categoryId' => $archived->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], self::list($owner, $organization)->toArray()['items']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_edits_a_manual_expense(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $food = self::addCategory($organization, 'Food');
        $id = self::enter($actor, $organization, [...self::RECEIPT, 'invoiceNumber' => 'PAR/1', 'vatLines' => [['rate' => '8', 'net' => 4_000, 'vat' => 320], ['rate' => '23', 'net' => 1_000, 'vat' => 230]]])->toArray()['id'];

        $expense = self::patch($actor, $organization, $id, [
            'sellerName' => 'Hala Targowa',
            'sellerTaxId' => '5265877635',
            'invoiceNumber' => null,
            'netTotal' => 6_000,
            'vatTotal' => 530,
            'grossTotal' => 6_530,
            'vatLines' => [['rate' => '8', 'net' => 4_000, 'vat' => 320], ['rate' => '5', 'net' => 2_000, 'vat' => 100]],
            'categoryId' => $food->getId()->toRfc4122(),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Hala Targowa', '5265877635', null, '2026-11-14', 'regular', 'PLN'], [$expense['sellerName'], $expense['sellerTaxId'], $expense['invoiceNumber'], $expense['issueDate'], $expense['kind'], $expense['currency']]);
        self::assertSame([6_000, 530, 6_530], [$expense['netTotal'], $expense['vatTotal'], $expense['grossTotal']]);
        self::assertSame([['rate' => '8', 'net' => 4_000, 'vat' => 320, 'vatInTaxCurrency' => null], ['rate' => '5', 'net' => 2_000, 'vat' => 100, 'vatInTaxCurrency' => null]], $expense['vatLines']);
        self::assertSame('Food', $expense['categoryName']);
        self::assertSame($expense, self::get($actor, $organization, $id)->toArray());

        $expense = self::patch($actor, $organization, $id, ['vatLines' => null])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $expense['vatLines']);
        self::assertSame('Hala Targowa', $expense['sellerName']);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidEdits(): iterable
    {
        yield 'null seller name' => [['sellerName' => null], ['sellerName']];
        yield 'null gross total' => [['grossTotal' => null], ['grossTotal']];
        yield 'a fraction' => [['grossTotal' => 1.5], ['grossTotal']];
        yield 'not a date' => [['issueDate' => '14.11.2026'], ['issueDate']];
        yield 'null currency' => [['currency' => null], ['currency']];
        yield 'unknown kind' => [['kind' => 'receipt'], ['kind']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidEdits')]
    public function test_it_answers_422_with_violations_for_an_invalid_edit(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::enter($owner, $organization, self::RECEIPT)->toArray()['id'];
        $before = self::get($owner, $organization, $id)->toArray();

        $response = self::patch($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame($before, self::get($owner, $organization, $id)->toArray());
    }

    public function test_it_answers_422_for_an_edit_the_domain_refuses(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::enter($owner, $organization, self::RECEIPT)->toArray()['id'];

        $response = self::patch($owner, $organization, $id, ['currency' => 'EUR']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('must also be given in PLN', $response->getContent(false));
        self::assertSame('PLN', self::get($owner, $organization, $id)->toArray()['currency']);
    }

    public function test_it_answers_404_for_an_unknown_expense(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::patch($owner, $organization, Uuid::v7()->toRfc4122(), ['sellerName' => 'Hala Targowa']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function editsOfAnImportedExpense(): iterable
    {
        yield 'seller name' => [['sellerName' => 'Someone else']];
        yield 'no invoice number' => [['invoiceNumber' => null]];
        yield 'gross total' => [['grossTotal' => 1]];
        yield 'VAT lines' => [['vatLines' => []]];
        yield 'with a category too' => [['issueDate' => '2026-11-11', 'categoryId' => null]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('editsOfAnImportedExpense')]
    public function test_it_answers_409_when_editing_an_imported_expense_and_changes_nothing(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $food = self::addCategory($organization, 'Food');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported(), category: $food)->getId()->toRfc4122();
        $before = self::get($owner, $organization, $id)->toArray();

        $response = self::patch($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('only its category can be changed', $response->getContent(false));
        self::assertSame($before, self::get($owner, $organization, $id)->toArray());
    }

    public function test_it_categorizes_an_imported_expense_sent_back_whole(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $food = self::addCategory($organization, 'Food');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported())->getId()->toRfc4122();
        $current = self::get($owner, $organization, $id)->toArray();
        $vatLines = array_map(static fn (array $line): array => ['rate' => $line['rate'], 'net' => $line['net'], 'vat' => $line['vat'], 'vatInTaxCurrency' => $line['vatInTaxCurrency']], $current['vatLines']);

        // A form that sends every field back unchanged, with a new category.
        $expense = self::patch($owner, $organization, $id, [
            'sellerName' => $current['sellerName'],
            'sellerTaxId' => $current['sellerTaxId'],
            'invoiceNumber' => $current['invoiceNumber'],
            'issueDate' => $current['issueDate'],
            'kind' => $current['kind'],
            'currency' => $current['currency'],
            'netTotal' => $current['netTotal'],
            'vatTotal' => $current['vatTotal'],
            'grossTotal' => $current['grossTotal'],
            'vatLines' => $vatLines,
            'categoryId' => $food->getId()->toRfc4122(),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([...$current, 'categoryId' => $food->getId()->toRfc4122(), 'categoryName' => 'Food'], $expense);
    }

    #[DataProvider('managingRoles')]
    public function test_it_deletes_a_manual_expense(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::enter($actor, $organization, self::RECEIPT)->toArray()['id'];
        $kept = self::enter($actor, $organization, self::RECEIPT)->toArray()['id'];

        self::delete($actor, $organization, $id);

        self::assertResponseStatusCodeSame(204);
        self::get($actor, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([$kept], array_column(self::list($actor, $organization)->toArray()['items'], 'id'));
    }

    public function test_it_answers_409_when_deleting_an_imported_expense(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported(), document: true)->getId()->toRfc4122();

        $response = self::delete($owner, $organization, $id);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('imported', $response->getContent(false));
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_hides_another_organizations_manual_expenses(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $id = self::enter($ownerElsewhere, $elsewhere, self::RECEIPT)->toArray()['id'];
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        self::assertSame([], self::list($owner, $organization)->toArray()['items']);
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::patch($owner, $organization, $id, ['sellerName' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::get($ownerElsewhere, $elsewhere, $id)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function writes(): iterable
    {
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::enter($user, $organization, self::RECEIPT)];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::patch($user, $organization, $id, ['sellerName' => 'Hala Targowa'])];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('writes')]
    public function test_it_refuses_writes_to_employees_deactivated_members_and_outsiders(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$outsider] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = self::enter($owner, $organization, self::RECEIPT)->toArray()['id'];
        $before = self::list($owner, $organization)->toArray();

        foreach ([$employee, $formerManager, $formerOwner, $outsider] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_expense(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::patch($employee, $organization, $unknown, ['sellerName' => 'Hala Targowa']);
        self::assertResponseStatusCodeSame(403);
        self::delete($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
    }

    private static function list(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expenses?from=2026-11-01&to=2026-11-30');
    }

    /** @param array<string, mixed> $body */
    private static function enter(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/expenses', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function patch(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/expenses/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/expenses/'.$id);
    }
}
