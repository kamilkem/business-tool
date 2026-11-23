<?php

declare(strict_types=1);

namespace App\Tests\Api\Expenses;

use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
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
 * GET /expenses, GET and PATCH /expenses/{id} and GET /expenses/{id}/document (#53). Invoices are
 * arranged as imported from KSeF (PurchaseInvoiceMother) in organizations of the factories (PL, PLN).
 */
final class ExpenseApiTest extends ExpensesApiTestCase
{
    private const string NOVEMBER = '?from=2026-11-01&to=2026-11-30';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_periods_expenses_newest_first_with_totals_per_currency(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $food = self::addCategory($organization, 'Food');
        $first = self::addInvoice($organization, PurchaseInvoiceMother::imported(externalId: 'K-1', issueDate: '2026-11-01', sellerName: 'Zakład Mięsny'), document: true, category: $food);
        $correction = self::addInvoice($organization, PurchaseInvoiceMother::imported(
            externalId: 'K-2',
            issueDate: '2026-11-30',
            kind: PurchaseInvoiceKind::Correction,
            totals: [-1_000, -80, -1_080],
            vatLines: [new PurchaseInvoiceVatAmount('8', -1_000, -80)],
            correctedExternalId: 'K-0',
        ));
        $euro = self::addInvoice($organization, PurchaseInvoiceMother::imported(
            externalId: 'K-3',
            issueDate: '2026-11-15',
            currency: 'EUR',
            totals: [10_000, 2_300, 12_300],
            vatLines: [new PurchaseInvoiceVatAmount('23', 10_000, 2_300, 9_800)],
        ));
        self::addInvoice($organization, PurchaseInvoiceMother::imported(issueDate: '2026-10-31'));
        self::addInvoice($organization, PurchaseInvoiceMother::imported(issueDate: '2026-12-01'));

        $list = self::list($actor, $organization, self::NOVEMBER)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-01', '2026-11-30'], [$list['from'], $list['to']]);
        self::assertSame(self::idsOf([$correction, $euro, $first]), array_column($list['items'], 'id'));
        self::assertSame([
            'id' => $first->getId()->toRfc4122(),
            'source' => 'ksef',
            'externalId' => 'K-1',
            'invoiceNumber' => 'FV/1/2026',
            'sellerTaxId' => '5265877635',
            'sellerName' => 'Zakład Mięsny',
            'issueDate' => '2026-11-01',
            'kind' => 'regular',
            'correctedExternalId' => null,
            'currency' => 'PLN',
            'taxCurrency' => 'PLN',
            'netTotal' => 20_000,
            'vatTotal' => 4_600,
            'grossTotal' => 24_600,
            'vatLines' => [['rate' => '23', 'net' => 20_000, 'vat' => 4_600, 'vatInTaxCurrency' => null]],
            'categoryId' => $food->getId()->toRfc4122(),
            'categoryName' => 'Food',
            'categorySuggested' => false,
            'hasDocument' => true,
            'documentFormat' => 'FA (3)',
            'createdAt' => '2026-11-12T21:00:00+00:00',
        ], $list['items'][2]);
        self::assertSame(['correction', 'K-0', -1_080, false, null], [
            $list['items'][0]['kind'],
            $list['items'][0]['correctedExternalId'],
            $list['items'][0]['grossTotal'],
            $list['items'][0]['hasDocument'],
            $list['items'][0]['categoryId'],
        ]);
        self::assertSame([
            [
                'currency' => 'PLN',
                'invoiceCount' => 2,
                'netTotal' => 19_000,
                'vatTotal' => 4_520,
                'grossTotal' => 23_520,
                'vatLines' => [
                    ['rate' => '23', 'net' => 20_000, 'vat' => 4_600, 'vatInTaxCurrency' => null],
                    ['rate' => '8', 'net' => -1_000, 'vat' => -80, 'vatInTaxCurrency' => null],
                ],
            ],
            [
                'currency' => 'EUR',
                'invoiceCount' => 1,
                'netTotal' => 10_000,
                'vatTotal' => 2_300,
                'grossTotal' => 12_300,
                'vatLines' => [['rate' => '23', 'net' => 10_000, 'vat' => 2_300, 'vatInTaxCurrency' => 9_800]],
            ],
        ], $list['totals']);
    }

    public function test_it_gives_zero_totals_in_the_organizations_currency_when_nothing_is_listed(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::assertSame([
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'items' => [],
            'totals' => [['currency' => 'PLN', 'invoiceCount' => 0, 'netTotal' => 0, 'vatTotal' => 0, 'grossTotal' => 0, 'vatLines' => []]],
        ], self::list($owner, $organization, self::NOVEMBER)->toArray());
    }

    public function test_it_filters_by_category_uncategorized_and_seller(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $beverages = self::addCategory($organization, 'Beverages');
        $oldRent = self::addCategory($organization, 'Old rent');
        $beer = self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerName: 'Browar Kormoran', sellerTaxId: '7393010870'), category: $beverages);
        $rent = self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerName: 'Kamienica', sellerTaxId: '1132853869'), category: $oldRent);
        $meat = self::addInvoice($organization, PurchaseInvoiceMother::imported(sellerName: 'Zakład Mięsny', sellerTaxId: '5265877635'));
        self::archiveCategory($organization, $oldRent);

        foreach ([
            '&categoryId='.$beverages->getId()->toRfc4122() => [$beer],
            '&categoryId='.$oldRent->getId()->toRfc4122() => [$rent],
            '&uncategorized=true' => [$meat],
            '&uncategorized=1' => [$meat],
            '&uncategorized=false&seller=kormoran' => [$beer],
            '&seller='.rawurlencode(' MIĘSNY') => [$meat],
            '&seller=739-301-08-70' => [$beer],
            '&seller=nobody' => [],
        ] as $query => $expected) {
            $list = self::list($owner, $organization, self::NOVEMBER.$query)->toArray();
            self::assertResponseStatusCodeSame(200, $query);
            self::assertSame(self::idsOf($expected), array_column($list['items'], 'id'), $query);
            self::assertSame(\count($expected), $list['totals'][0]['invoiceCount'], $query);
        }
    }

    public function test_it_lists_a_whole_leap_year(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invoice = self::addInvoice($organization, PurchaseInvoiceMother::imported(issueDate: '2028-12-31'));

        $list = self::list($owner, $organization, '?from=2028-01-01&to=2028-12-31')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::idsOf([$invoice]), array_column($list['items'], 'id'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function invalidLists(): iterable
    {
        yield 'no period' => ['', ['from', 'to']];
        yield 'no to' => ['?from=2026-11-01', ['to']];
        yield 'not a date' => ['?from=2026-11-31&to=2026-12-01', ['from']];
        yield 'to before from' => ['?from=2026-11-02&to=2026-11-01', ['to']];
        yield '367 days' => ['?from=2026-01-01&to=2027-01-02', ['to']];
        yield 'from as an array' => ['?from[]=2026-11-01&to=2026-11-30', ['from']];
        yield 'category not a UUID' => [self::NOVEMBER.'&categoryId=food', ['categoryId']];
        yield 'uncategorized not a boolean' => [self::NOVEMBER.'&uncategorized=maybe', ['uncategorized']];
        yield 'category and uncategorized' => [self::NOVEMBER.'&categoryId=0196f0b4-0000-7000-8000-000000000000&uncategorized=true', ['uncategorized']];
        yield 'seller too long' => [self::NOVEMBER.'&seller='.str_repeat('a', 513), ['seller']];
    }

    /** @param list<string> $paths */
    #[DataProvider('invalidLists')]
    public function test_it_answers_422_with_violations_for_an_invalid_list(string $query, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::list($owner, $organization, $query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
    }

    public function test_it_answers_404_for_a_category_filter_of_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Food');

        self::list($owner, $organization, self::NOVEMBER.'&categoryId='.$foreign->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::list($owner, $organization, self::NOVEMBER.'&categoryId='.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('managingRoles')]
    public function test_it_shows_one_expense(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $invoice = self::addInvoice($organization, PurchaseInvoiceMother::imported(
            externalId: 'K-1',
            currency: 'EUR',
            totals: [11_000, 880, 11_880],
            vatLines: [new PurchaseInvoiceVatAmount('8', 10_000, 800, 3_400), new PurchaseInvoiceVatAmount('zw', 1_000, 0, 0)],
        ));

        $expense = self::get($actor, $organization, $invoice->getId()->toRfc4122())->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'id' => $invoice->getId()->toRfc4122(),
            'source' => 'ksef',
            'externalId' => 'K-1',
            'invoiceNumber' => 'FV/1/2026',
            'sellerTaxId' => '5265877635',
            'sellerName' => 'Hurtownia Napojów',
            'issueDate' => '2026-11-10',
            'kind' => 'regular',
            'correctedExternalId' => null,
            'currency' => 'EUR',
            'taxCurrency' => 'PLN',
            'netTotal' => 11_000,
            'vatTotal' => 880,
            'grossTotal' => 11_880,
            'vatLines' => [
                ['rate' => '8', 'net' => 10_000, 'vat' => 800, 'vatInTaxCurrency' => 3_400],
                ['rate' => 'zw', 'net' => 1_000, 'vat' => 0, 'vatInTaxCurrency' => 0],
            ],
            'categoryId' => null,
            'categoryName' => null,
            'categorySuggested' => false,
            'hasDocument' => false,
            'documentFormat' => null,
            'createdAt' => '2026-11-12T21:00:00+00:00',
        ], $expense);
    }

    public function test_it_answers_404_for_an_unknown_expense(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::document($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::categorize($owner, $organization, $unknown, ['categoryId' => null]);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('GET', '/api/expenses/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('managingRoles')]
    public function test_it_serves_the_original_document_from_the_local_copy(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $invoice = self::addInvoice($organization, PurchaseInvoiceMother::imported(), document: true);
        $id = $invoice->getId()->toRfc4122();

        foreach (['application/xml', '*/*', 'application/xml, application/json;q=0.9'] as $accept) {
            $response = self::document($actor, $organization, $id, $accept);

            self::assertResponseStatusCodeSame(200, $accept);
            self::assertSame(self::XML, $response->getContent());
            self::assertStringStartsWith('application/xml', $response->getHeaders()['content-type'][0]);
            self::assertSame('attachment; filename='.$id.'.xml', $response->getHeaders()['content-disposition'][0]);
            self::assertSame('"'.hash('sha256', self::XML).'"', $response->getHeaders()['etag'][0]);
        }

        // The document is not JSON: a client that only takes JSON is told so.
        self::document($actor, $organization, $id, 'application/json');
        self::assertResponseStatusCodeSame(406);
    }

    public function test_it_answers_404_for_the_document_of_an_expense_without_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invoice = self::addInvoice($organization, PurchaseInvoiceMother::imported());

        $response = self::document($owner, $organization, $invoice->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('has no original document', $response->getContent(false));
    }

    #[DataProvider('managingRoles')]
    public function test_it_sets_changes_and_clears_the_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $food = self::addCategory($organization, 'Food');
        $beverages = self::addCategory($organization, 'Beverages');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported())->getId()->toRfc4122();

        $expense = self::categorize($actor, $organization, $id, ['categoryId' => $food->getId()->toRfc4122()])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$food->getId()->toRfc4122(), 'Food'], [$expense['categoryId'], $expense['categoryName']]);
        self::assertSame($expense, self::get($actor, $organization, $id)->toArray());

        $expense = self::categorize($actor, $organization, $id, ['categoryId' => $beverages->getId()->toRfc4122()])->toArray();
        self::assertSame([$beverages->getId()->toRfc4122(), 'Beverages'], [$expense['categoryId'], $expense['categoryName']]);

        self::categorize($actor, $organization, $id, []);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($beverages->getId()->toRfc4122(), self::get($actor, $organization, $id)->toArray()['categoryId']);

        $expense = self::categorize($actor, $organization, $id, ['categoryId' => null])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([null, null], [$expense['categoryId'], $expense['categoryName']]);
        self::assertSame([null, null], [self::get($actor, $organization, $id)->toArray()['categoryId'], self::get($actor, $organization, $id)->toArray()['categoryName']]);
    }

    public function test_it_refuses_an_archived_category_but_keeps_one_already_assigned(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $rent = self::addCategory($organization, 'Rent');
        $oldRent = self::addCategory($organization, 'Old rent');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported(), category: $rent)->getId()->toRfc4122();
        self::archiveCategory($organization, $rent);
        self::archiveCategory($organization, $oldRent);

        $response = self::categorize($owner, $organization, $id, ['categoryId' => $oldRent->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('archived', $response->getContent(false));
        self::assertSame($rent->getId()->toRfc4122(), self::get($owner, $organization, $id)->toArray()['categoryId']);

        $expense = self::categorize($owner, $organization, $id, ['categoryId' => $rent->getId()->toRfc4122()])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$rent->getId()->toRfc4122(), 'Rent'], [$expense['categoryId'], $expense['categoryName']]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCategorizations(): iterable
    {
        yield 'not a UUID' => [['categoryId' => 'food']];
        yield 'a number' => [['categoryId' => 123]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidCategorizations')]
    public function test_it_answers_422_with_violations_for_an_invalid_category(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported())->getId()->toRfc4122();

        $response = self::categorize($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['categoryId'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertNull(self::get($owner, $organization, $id)->toArray()['categoryId']);
    }

    public function test_it_answers_404_for_a_category_of_another_organization_or_an_unknown_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Food');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported())->getId()->toRfc4122();

        foreach ([$foreign->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $categoryId) {
            self::categorize($owner, $organization, $id, ['categoryId' => $categoryId]);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertNull(self::get($owner, $organization, $id)->toArray()['categoryId']);
    }

    public function test_it_hides_another_organizations_expenses(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $mine = self::addInvoice($organization, PurchaseInvoiceMother::imported(externalId: 'K-1'));
        $foreign = self::addInvoice($elsewhere, PurchaseInvoiceMother::imported(externalId: 'K-1'), document: true);
        $food = self::addCategory($organization, 'Food');
        $id = $foreign->getId()->toRfc4122();
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        $list = self::list($owner, $organization, self::NOVEMBER)->toArray();
        self::assertSame(self::idsOf([$mine]), array_column($list['items'], 'id'));
        self::assertSame(1, $list['totals'][0]['invoiceCount']);
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::document($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::categorize($owner, $organization, $id, ['categoryId' => $food->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::get($ownerElsewhere, $elsewhere, $id)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET list' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, self::NOVEMBER)];
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'GET document' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::document($user, $organization, $id)];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::categorize($user, $organization, $id, ['categoryId' => null])];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_expenses_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $food = self::addCategory($organization, 'Food');
        $id = self::addInvoice($organization, PurchaseInvoiceMother::imported(), document: true, category: $food)->getId()->toRfc4122();
        $before = self::get($owner, $organization, $id)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::get($owner, $organization, $id)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_expense(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::document($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::categorize($employee, $organization, $unknown, ['categoryId' => null]);
        self::assertResponseStatusCodeSame(403);
        self::list($employee, $organization, '?from=2026-11-01&to=2026-11-30&categoryId='.$unknown);
        self::assertResponseStatusCodeSame(403);
    }

    private static function list(User $user, Organization $organization, string $query): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expenses'.$query);
    }

    private static function document(User $user, Organization $organization, string $id, string $accept = 'application/xml'): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expenses/'.$id.'/document', ['headers' => ['Accept' => $accept]]);
    }

    /** @param array<string, mixed> $body */
    private static function categorize(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/expenses/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    /**
     * @param list<PurchaseInvoice> $invoices
     *
     * @return list<string>
     */
    private static function idsOf(array $invoices): array
    {
        return array_map(static fn (PurchaseInvoice $invoice): string => $invoice->getId()->toRfc4122(), $invoices);
    }
}
