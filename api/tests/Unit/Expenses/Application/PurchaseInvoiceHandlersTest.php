<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\ChangePurchaseInvoice\ChangePurchaseInvoiceCommand;
use App\Expenses\Application\Command\ChangePurchaseInvoice\ChangePurchaseInvoiceHandler;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceHandler;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceQuery;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceItemView;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceVatLineView;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentHandler;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentQuery;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\PurchaseInvoiceDocumentView;
use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesHandler;
use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesQuery;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceCurrencyTotalView;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceListView;
use App\Expenses\Domain\Exception\ExpensePeriodTooLongException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\TimeException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A Polish organization (PLN) with an owner, a manager, an employee and imported invoices; the Organization module answers through its real queries over
 * an in-memory repository.
 */
#[CoversClass(ListPurchaseInvoicesHandler::class)]
#[CoversClass(GetPurchaseInvoiceHandler::class)]
#[CoversClass(GetPurchaseInvoiceDocumentHandler::class)]
#[CoversClass(ChangePurchaseInvoiceHandler::class)]
#[CoversClass(PurchaseInvoiceView::class)]
#[CoversClass(PurchaseInvoiceVatLineView::class)]
#[CoversClass(PurchaseInvoiceItemView::class)]
#[CoversClass(PurchaseInvoiceCurrencyTotalView::class)]
#[CoversClass(PurchaseInvoiceDocumentView::class)]
final class PurchaseInvoiceHandlersTest extends TestCase
{
    private const string JOINED = '2026-01-05 09:00:00';
    private const string XML = '<?xml version="1.0"?><Faktura/>';

    private InMemoryPurchaseInvoiceRepository $invoices;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_lists_the_periods_invoices_newest_first_with_totals_per_currency(string $actor): void
    {
        $older = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-11-01', sellerName: 'Zakład Mięsny'), document: true);
        $sameDayB = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-11-10', sellerName: 'Browar', invoiceNumber: 'B/2'));
        $sameDayA = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-11-10', sellerName: 'Browar', invoiceNumber: 'B/1'));
        $correction = $this->store(PurchaseInvoiceMother::imported(
            issueDate: '2026-11-30',
            kind: PurchaseInvoiceKind::Correction,
            totals: [-1_000, -80, -1_080],
            vatLines: [new PurchaseInvoiceVatAmount('8', -1_000, -80)],
        ));
        $euro = $this->store(PurchaseInvoiceMother::imported(
            issueDate: '2026-11-15',
            currency: 'EUR',
            totals: [10_000, 2_300, 12_300],
            vatLines: [new PurchaseInvoiceVatAmount('23', 10_000, 2_300, 9_800)],
        ));
        $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-10-31'));
        $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-12-01'));

        $list = $this->listed($this->user($actor));

        self::assertSame(['2026-11-01', '2026-11-30'], [$list->from, $list->to]);
        self::assertSame(
            array_map(static fn (PurchaseInvoice $invoice): string => $invoice->getId()->toRfc4122(), [$correction, $euro, $sameDayA, $sameDayB, $older]),
            self::ids($list),
        );
        self::assertSame(['FA (3)', null], [$list->items[4]->documentFormat, $list->items[0]->documentFormat]);
        self::assertEquals([
            new PurchaseInvoiceCurrencyTotalView('PLN', 4, 59_000, 13_720, 72_720, [
                new PurchaseInvoiceVatLineView('23', 60_000, 13_800, null),
                new PurchaseInvoiceVatLineView('8', -1_000, -80, null),
            ]),
            new PurchaseInvoiceCurrencyTotalView('EUR', 1, 10_000, 2_300, 12_300, [new PurchaseInvoiceVatLineView('23', 10_000, 2_300, 9_800)]),
        ], $list->totals);
    }

    public function test_it_gives_zero_totals_in_the_organizations_currency_for_an_empty_period(): void
    {
        $list = $this->listed($this->owner);

        self::assertSame([], $list->items);
        self::assertEquals([new PurchaseInvoiceCurrencyTotalView('PLN', 0, 0, 0, 0, [])], $list->totals);
    }

    public function test_it_filters_by_seller(): void
    {
        $beer = $this->store(PurchaseInvoiceMother::imported(sellerName: 'Browar Kormoran', sellerTaxId: '7393010870'));
        $meat = $this->store(PurchaseInvoiceMother::imported(sellerName: 'Zakład Mięsny', sellerTaxId: '5265877635'));
        $this->store(PurchaseInvoiceMother::imported(sellerName: 'Kamienica sp. z o.o.', sellerTaxId: '1132853869'));

        self::assertSame([$meat->getId()->toRfc4122()], self::ids($this->listed($this->owner, seller: ' MIĘSNY ')));
        $filtered = $this->listed($this->owner, seller: '739-301-08 70');
        self::assertSame([$beer->getId()->toRfc4122()], self::ids($filtered));
        self::assertSame(1, $filtered->totals[0]->invoiceCount);
    }

    /** @return iterable<string, array{string, string, class-string<\Throwable>}> */
    public static function invalidLists(): iterable
    {
        yield 'bad date' => ['2026-11-31', '2026-12-01', TimeException::class];
        yield 'to before from' => ['2026-11-02', '2026-11-01', TimeException::class];
        yield '367 days' => ['2026-01-01', '2027-01-02', ExpensePeriodTooLongException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('invalidLists')]
    public function test_it_refuses_an_invalid_list(string $from, string $to, string $exception): void
    {
        $this->expectException($exception);

        $this->list($this->owner, $from, $to);
    }

    public function test_it_lists_a_leap_year(): void
    {
        $this->store(PurchaseInvoiceMother::imported(issueDate: '2028-12-31'));

        self::assertCount(1, $this->listed($this->owner, '2028-01-01', '2028-12-31')->items);
    }

    public function test_it_gets_one_invoice_with_its_lines_items_and_document(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(
            externalId: 'K-1',
            totals: [20_000, 3_100, 23_100],
            vatLines: [new PurchaseInvoiceVatAmount('8', 10_000, 800), new PurchaseInvoiceVatAmount('23', 10_000, 2_300)],
            items: [
                new PurchaseInvoiceItemDetails(position: 2, name: 'Przekąski', unit: 'kg', quantity: '0.700', unitNetPrice: '12.34567891', netAmount: 10_000, vatRate: '8'),
                new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo', sellerCode: 'P-1', gtin: '5901234123457', quantity: '4', unitGrossPrice: '30.75', discount: '1', grossAmount: 12_300, vatAmount: 2_300, vatRate: '23', beforeCorrection: true),
            ],
        ), document: true);

        $view = $this->get($this->manager, $invoice->getId()->toRfc4122());

        self::assertEquals(new PurchaseInvoiceView(
            $invoice->getId()->toRfc4122(),
            'ksef',
            'K-1',
            'FV/1/2026',
            '5265877635',
            'Hurtownia Napojów',
            '2026-11-10',
            'regular',
            null,
            'PLN',
            'PLN',
            20_000,
            3_100,
            23_100,
            [new PurchaseInvoiceVatLineView('23', 10_000, 2_300, null), new PurchaseInvoiceVatLineView('8', 10_000, 800, null)],
            'FA (3)',
            new \DateTimeImmutable(PurchaseInvoiceMother::IMPORTED_AT),
            [
                new PurchaseInvoiceItemView(1, 'Piwo', 'P-1', '5901234123457', null, '4', null, '30.75', '1', null, 12_300, 2_300, '23', true),
                new PurchaseInvoiceItemView(2, 'Przekąski', null, null, 'kg', '0.7', '12.34567891', null, null, 10_000, null, null, '8', false),
            ],
        ), $view);
    }

    public function test_it_lists_invoices_without_their_items(): void
    {
        $this->store(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));

        $list = $this->listed($this->owner);

        self::assertCount(1, $list->items);
        self::assertNull($list->items[0]->items);
    }

    public function test_it_gets_an_invoice_without_items_with_none(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::manual());

        self::assertSame([], $this->get($this->owner, $invoice->getId()->toRfc4122())?->items);
    }

    public function test_it_gets_nothing_for_an_unknown_invoice_or_a_stranger(): void
    {
        $id = $this->store(PurchaseInvoiceMother::imported(), document: true)->getId()->toRfc4122();
        $stranger = UserMother::any('stranger@example.com');

        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
        self::assertNull($this->get($stranger, $id));
        self::assertNull($this->list($stranger));
        self::assertNull($this->document($stranger, $id));
    }

    public function test_it_gets_the_original_document(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(), document: true);
        $without = $this->store(PurchaseInvoiceMother::imported());

        self::assertEquals(new PurchaseInvoiceDocumentView(
            $invoice->getId()->toRfc4122(),
            self::XML,
            'FA (3)',
            hash('sha256', self::XML),
            new \DateTimeImmutable('2026-11-12 21:00:05'),
        ), $this->document($this->owner, $invoice->getId()->toRfc4122()));
        self::assertNull($this->document($this->owner, $without->getId()->toRfc4122()));
        self::assertNull($this->document($this->owner, 'not-a-uuid'));
    }

    public function test_it_refuses_reads_to_an_employee(): void
    {
        $id = $this->store(PurchaseInvoiceMother::imported(), document: true)->getId()->toRfc4122();
        $refused = 0;

        foreach ([
            fn () => $this->list($this->employee),
            fn () => $this->get($this->employee, $id),
            fn () => $this->document($this->employee, $id),
        ] as $read) {
            try {
                $read();
            } catch (ExpensesManagementForbiddenException) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
    }

    #[DataProvider('managers')]
    public function test_it_changes_nothing_when_an_imported_invoice_gets_its_own_details_back(string $actor): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());
        $before = $this->get($this->owner, $invoice->getId()->toRfc4122());

        $this->handleResend($this->user($actor), $invoice->getId()->toRfc4122());

        self::assertEquals($before, $this->get($this->owner, $invoice->getId()->toRfc4122()));
        self::assertSame(1, $this->invoices->saveCallCount);
    }

    public function test_it_refuses_an_unknown_invoice(): void
    {
        $refused = 0;
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $invoiceId) {
            try {
                $this->handleResend($this->owner, $invoiceId);
            } catch (PurchaseInvoiceNotFoundException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
        self::assertSame(0, $this->invoices->saveCallCount);
    }

    public function test_it_refuses_a_change_to_an_employee_before_looking_for_the_invoice(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        $this->handleResend($this->employee, Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_a_change_to_a_deactivated_member_or_stranger(): void
    {
        $id = $this->store(PurchaseInvoiceMother::imported())->getId()->toRfc4122();
        OrganizationMother::deactivateMember($this->organization, $this->manager, new \DateTimeImmutable(self::JOINED));

        foreach ([$this->manager, UserMother::any('stranger@example.com')] as $user) {
            try {
                $this->handleResend($user, $id);
                self::fail('Not found expected.');
            } catch (OrganizationNotFoundException) {
            }
        }

        self::assertSame(0, $this->invoices->saveCallCount);
    }

    private function store(PurchaseInvoice $invoice, bool $document = false): PurchaseInvoice
    {
        $this->invoices->add($invoice, $document ? PurchaseInvoiceDocument::of($invoice, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')) : null);

        return $invoice;
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function list(User $user, string $from = '2026-11-01', string $to = '2026-11-30', ?string $seller = null): ?PurchaseInvoiceListView
    {
        $handler = new ListPurchaseInvoicesHandler($this->invoices, $this->invoices, $this->queryBus);

        return $handler(new ListPurchaseInvoicesQuery($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $from, $to, $seller));
    }

    private function listed(User $user, string $from = '2026-11-01', string $to = '2026-11-30', ?string $seller = null): PurchaseInvoiceListView
    {
        return $this->list($user, $from, $to, $seller) ?? self::fail('A list expected.');
    }

    /** @return list<string> */
    private static function ids(PurchaseInvoiceListView $list): array
    {
        return array_map(static fn (PurchaseInvoiceView $view): string => $view->id, $list->items);
    }

    private function get(User $user, string $invoiceId): ?PurchaseInvoiceView
    {
        $handler = new GetPurchaseInvoiceHandler($this->invoices, $this->invoices, $this->queryBus);

        return $handler(new GetPurchaseInvoiceQuery($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $invoiceId));
    }

    private function document(User $user, string $invoiceId): ?PurchaseInvoiceDocumentView
    {
        $handler = new GetPurchaseInvoiceDocumentHandler($this->invoices, $this->queryBus, new InMemoryTenantContext());

        return $handler(new GetPurchaseInvoiceDocumentQuery($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $invoiceId));
    }

    /** Sends the invoice's own details back (an imported invoice accepts no others). */
    private function handleResend(User $user, string $invoiceId): void
    {
        $handler = new ChangePurchaseInvoiceHandler($this->invoices, $this->queryBus);
        $invoice = Uuid::isValid($invoiceId) ? $this->invoices->findById(Uuid::fromString($invoiceId)) : null;
        $invoice ??= PurchaseInvoiceMother::imported();

        $handler(new ChangePurchaseInvoiceCommand(
            $this->organization->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $invoiceId,
            $invoice->getInvoiceNumber(),
            $invoice->getSellerTaxId(),
            $invoice->getSellerName(),
            $invoice->getIssueDate()->toString(),
            $invoice->getKind()->value,
            $invoice->getCurrency(),
            $invoice->getNetTotal(),
            $invoice->getVatTotal(),
            $invoice->getGrossTotal(),
            array_map(static fn (PurchaseInvoiceVatAmount $line): array => ['rate' => $line->rate, 'net' => $line->net, 'vat' => $line->vat, 'vatInTaxCurrency' => $line->vatInTaxCurrency], $invoice->getVatLines()),
        ));
    }
}
