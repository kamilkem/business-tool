<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceHandler;
use App\Expenses\Application\Query\SuggestExpenseCategory\ExpenseCategorySuggestionView;
use App\Expenses\Application\Query\SuggestExpenseCategory\SuggestExpenseCategoryHandler;
use App\Expenses\Application\Query\SuggestExpenseCategory\SuggestExpenseCategoryQuery;
use App\Expenses\Application\SellerCategorySuggestion;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * A Polish organization with an owner, a manager, an employee and the categories "Beverages",
 * "Food" and "Old rent"; invoices of the seller "Hurtownia Napojów" (5265877635) and others.
 */
#[CoversClass(SellerCategorySuggestion::class)]
#[CoversClass(SuggestExpenseCategoryHandler::class)]
#[CoversClass(ExpenseCategorySuggestionView::class)]
#[CoversClass(ImportPurchaseInvoiceHandler::class)]
final class SellerCategorySuggestionTest extends TestCase
{
    private const string JOINED = '2026-01-05 09:00:00';
    private const string TAX_ID = '5265877635';

    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryExpenseCategoryRepository $categories;
    private SellerCategorySuggestion $suggestion;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private ExpenseCategory $beverages;
    private ExpenseCategory $food;
    private ExpenseCategory $oldRent;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->categories = new InMemoryExpenseCategoryRepository();
        $this->invoices = new InMemoryPurchaseInvoiceRepository($this->categories);
        $this->suggestion = new SellerCategorySuggestion($this->invoices, $this->categories);
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->beverages = ExpenseCategory::create(Uuid::v7(), 'Beverages', null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->food = ExpenseCategory::create(Uuid::v7(), 'Food', null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->oldRent = ExpenseCategory::create(Uuid::v7(), 'Old rent', null, $this->owner->getId(), MembershipRole::Owner, $joined);
        foreach ([$this->beverages, $this->food, $this->oldRent] as $category) {
            $this->categories->add($category);
        }

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    public function test_it_suggests_the_category_used_most_often_for_the_seller(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-09-01'), $this->food);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-09-15'), $this->food);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-11-01'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(sellerTaxId: '1111111111', issueDate: '2026-11-02'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(sellerTaxId: '1111111111', issueDate: '2026-11-03'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-11-04'));

        self::assertSame($this->food, $this->suggestion->forSeller(self::TAX_ID, 'Hurtownia Napojów'));
    }

    public function test_it_breaks_a_tie_with_the_sellers_latest_invoice(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-11-01'), $this->food);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-10-01'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-11-20'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(issueDate: '2026-09-01'), $this->food);

        self::assertSame($this->beverages, $this->suggestion->forSeller(self::TAX_ID, null));
    }

    public function test_it_matches_the_tax_id_without_spaces_or_dashes_and_ignores_the_name(): void
    {
        $this->stored(PurchaseInvoiceMother::manual(sellerName: 'Hurtownia N.', sellerTaxId: '526-587-76-35'), $this->food);

        self::assertSame($this->food, $this->suggestion->forSeller(' 526 587 76 35 ', 'Someone else'));
        self::assertNull($this->suggestion->forSeller('9999999999', 'Hurtownia N.'));
    }

    public function test_it_matches_the_name_ignoring_case_when_there_is_no_tax_id(): void
    {
        $this->stored(PurchaseInvoiceMother::manual(sellerName: 'Targ Rybny'), $this->food);
        $this->stored(PurchaseInvoiceMother::imported(sellerName: 'Targ Rybny', sellerTaxId: '1111111111'), $this->food);
        $this->stored(PurchaseInvoiceMother::manual(sellerName: 'Targ Rybny Nowy'), $this->beverages);
        $this->stored(PurchaseInvoiceMother::manual(sellerName: 'Targ Rybny Nowy'), $this->beverages);

        self::assertSame($this->food, $this->suggestion->forSeller(null, '  targ rybny '));
        self::assertSame($this->food, $this->suggestion->forSeller('  ', 'TARG RYBNY'));
    }

    public function test_it_suggests_nothing_without_history_or_seller(): void
    {
        $this->stored(PurchaseInvoiceMother::imported());

        self::assertNull($this->suggestion->forSeller(self::TAX_ID, 'Hurtownia Napojów'));
        self::assertNull($this->suggestion->forSeller(null, '  '));
        self::assertNull($this->suggestion->forSeller(null, null));
    }

    public function test_it_skips_archived_categories_and_unconfirmed_suggestions(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(), $this->oldRent);
        $this->stored(PurchaseInvoiceMother::imported(), $this->oldRent);
        $this->stored(PurchaseInvoiceMother::imported(), $this->food);
        $suggested = PurchaseInvoiceMother::imported();
        $suggested->suggestCategory($this->beverages);
        $this->stored($suggested);
        $suggested = PurchaseInvoiceMother::imported();
        $suggested->suggestCategory($this->beverages);
        $this->stored($suggested);
        $this->oldRent->archive($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));

        self::assertSame($this->food, $this->suggestion->forSeller(self::TAX_ID, null));
    }

    public function test_it_pre_fills_an_imported_invoice_marked_as_suggested(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);

        $invoice = $this->import(new PurchaseInvoiceDetails('FV/9/2026', self::TAX_ID, 'Hurtownia Napojów', BusinessDate::fromString('2026-11-20'), PurchaseInvoiceKind::Regular, 'PLN', 1_000, 230, 1_230, [new PurchaseInvoiceVatAmount('23', 1_000, 230)]));

        self::assertTrue($this->beverages->getId()->equals($invoice->getCategoryId()));
        self::assertTrue($invoice->isCategorySuggested());
    }

    public function test_it_imports_an_invoice_of_a_new_seller_uncategorized(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);

        $invoice = $this->import(new PurchaseInvoiceDetails('A/1', '7777777777', 'Nowy Dostawca', BusinessDate::fromString('2026-11-20'), PurchaseInvoiceKind::Regular, 'PLN', 1_000, 230, 1_230, [new PurchaseInvoiceVatAmount('23', 1_000, 230)]));

        self::assertNull($invoice->getCategoryId());
        self::assertFalse($invoice->isCategorySuggested());
    }

    public function test_it_gives_a_correction_the_category_of_the_invoice_it_corrects(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(externalId: 'K-ORIGINAL'), $this->food);
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);

        $correction = $this->import($this->correction('K-ORIGINAL'));

        self::assertTrue($this->food->getId()->equals($correction->getCategoryId()));
        self::assertTrue($correction->isCategorySuggested());
    }

    public function test_it_gives_a_correction_the_sellers_category_when_the_corrected_one_is_not_stored_uncategorized_or_archived(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);
        $this->stored(PurchaseInvoiceMother::imported(externalId: 'K-UNCATEGORIZED'));
        $this->stored(PurchaseInvoiceMother::imported(externalId: 'K-ARCHIVED'), $this->oldRent);
        $this->oldRent->archive($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));

        foreach (['K-MISSING', 'K-UNCATEGORIZED', 'K-ARCHIVED'] as $corrected) {
            $correction = $this->import($this->correction($corrected));
            self::assertTrue($this->beverages->getId()->equals($correction->getCategoryId()), $corrected);
        }
    }

    public function test_it_answers_the_suggestion_query_for_owners_and_managers(): void
    {
        $this->stored(PurchaseInvoiceMother::imported(), $this->beverages);

        foreach ([$this->owner, $this->manager] as $user) {
            self::assertEquals(
                new ExpenseCategorySuggestionView($this->beverages->getId()->toRfc4122(), 'Beverages'),
                $this->ask($user, self::TAX_ID, null),
            );
        }
        self::assertEquals(new ExpenseCategorySuggestionView(null, null), $this->ask($this->owner, null, 'Nobody'));
    }

    public function test_it_answers_nothing_to_a_stranger_and_refuses_an_employee(): void
    {
        self::assertNull($this->ask(UserMother::any('stranger@example.com'), self::TAX_ID, null));

        $this->expectException(ExpensesManagementForbiddenException::class);
        $this->ask($this->employee, self::TAX_ID, null);
    }

    private function stored(PurchaseInvoice $invoice, ?ExpenseCategory $category = null): void
    {
        if (null !== $category) {
            $invoice->categorize($category, $this->owner->getId(), MembershipRole::Owner);
        }
        $this->invoices->add($invoice);
    }

    private function correction(string $correctedExternalId): PurchaseInvoiceDetails
    {
        return new PurchaseInvoiceDetails('KOR/1/2026', self::TAX_ID, 'Hurtownia Napojów', BusinessDate::fromString('2026-11-21'), PurchaseInvoiceKind::Correction, 'PLN', -1_000, -230, -1_230, [new PurchaseInvoiceVatAmount('23', -1_000, -230)], $correctedExternalId);
    }

    private function import(PurchaseInvoiceDetails $details): PurchaseInvoice
    {
        $tenantContext = new InMemoryTenantContext();
        $tenantContext->switchTo($this->organization);
        $handler = new ImportPurchaseInvoiceHandler($this->invoices, $tenantContext, new MockClock('2026-11-21 21:00:00'), $this->suggestion);
        $id = Uuid::v7();

        $handler(ImportPurchaseInvoiceCommand::of($this->organization->getId()->toRfc4122(), $id->toRfc4122(), PurchaseInvoiceSource::Ksef->value, 'K-'.Uuid::v4()->toBase58(), $details, '<Faktura/>', 'FA (3)'));

        return $this->invoices->findById($id) ?? throw new \LogicException('Not imported.');
    }

    private function ask(User $user, ?string $sellerTaxId, ?string $sellerName): ?ExpenseCategorySuggestionView
    {
        $handler = new SuggestExpenseCategoryHandler($this->suggestion, $this->queryBus);

        return $handler(new SuggestExpenseCategoryQuery($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $sellerTaxId, $sellerName));
    }
}
