<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\ChangePurchaseInvoice\ChangePurchaseInvoiceCommand;
use App\Expenses\Application\Command\ChangePurchaseInvoice\ChangePurchaseInvoiceHandler;
use App\Expenses\Application\Command\DeletePurchaseInvoice\DeletePurchaseInvoiceCommand;
use App\Expenses\Application\Command\DeletePurchaseInvoice\DeletePurchaseInvoiceHandler;
use App\Expenses\Application\Command\EnterPurchaseInvoice\EnterPurchaseInvoiceCommand;
use App\Expenses\Application\Command\EnterPurchaseInvoice\EnterPurchaseInvoiceHandler;
use App\Expenses\Application\Command\PurchaseInvoiceDetailsInput;
use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Exception\PurchaseInvoiceReadOnlyException;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Entering, changing and deleting expenses by hand (#54) in a Polish organization (PLN) with an
 * owner, a manager and an employee.
 */
#[CoversClass(EnterPurchaseInvoiceHandler::class)]
#[CoversClass(ChangePurchaseInvoiceHandler::class)]
#[CoversClass(DeletePurchaseInvoiceHandler::class)]
#[CoversClass(PurchaseInvoiceDetailsInput::class)]
#[CoversClass(ExpensesActor::class)]
final class ManualPurchaseInvoiceHandlersTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';

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
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
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
    public function test_it_enters_an_expense_in_the_organizations_currency(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->enter($this->user($actor), $id, currency: null);

        $invoice = $this->invoices->findById(Uuid::fromString($id)) ?? self::fail('Stored invoice expected.');
        self::assertSame(PurchaseInvoiceSource::Manual, $invoice->getSource());
        self::assertNull($invoice->getExternalId());
        self::assertNull($invoice->getInvoiceNumber());
        self::assertSame('Targ Rybny', $invoice->getSellerName());
        self::assertSame('2026-11-14', $invoice->getIssueDate()->toString());
        self::assertSame(PurchaseInvoiceKind::Regular, $invoice->getKind());
        self::assertSame('PLN', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertSame(5_400, $invoice->getGrossTotal());
        self::assertEquals([new PurchaseInvoiceVatAmount('8', 5_000, 400)], $invoice->getVatLines());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $invoice->getCreatedAt());
    }

    public function test_it_enters_an_expense_in_another_currency_with_the_vat_in_the_organizations(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->enter($this->owner, $id, currency: 'EUR', vatLines: [['rate' => '23', 'net' => 1_000, 'vat' => 230, 'vatInTaxCurrency' => 990]]);

        $invoice = $this->invoices->findById(Uuid::fromString($id)) ?? self::fail('Stored invoice expected.');
        self::assertSame('EUR', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 1_000, 230, 990)], $invoice->getVatLines());
    }

    public function test_it_refuses_an_unknown_kind_or_an_invalid_date(): void
    {
        $refused = [];
        foreach ([['receipt', '2026-11-14'], ['regular', '14.11.2026']] as [$kind, $date]) {
            try {
                $this->enter($this->owner, Uuid::v7()->toRfc4122(), kind: $kind, issueDate: $date);
            } catch (InvalidPurchaseInvoiceException|InvalidBusinessDateException $e) {
                $refused[] = $e::class;
            }
        }

        self::assertSame([InvalidPurchaseInvoiceException::class, InvalidBusinessDateException::class], $refused);
    }

    public function test_it_refuses_entering_to_an_employee_a_deactivated_member_or_a_stranger(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->manager, new \DateTimeImmutable(self::NOW));

        $refused = [];
        foreach ([$this->employee, $this->manager, UserMother::any('stranger@example.com')] as $user) {
            try {
                $this->enter($user, Uuid::v7()->toRfc4122());
            } catch (ExpensesManagementForbiddenException|OrganizationNotFoundException $e) {
                $refused[] = $e::class;
            }
        }

        self::assertSame([ExpensesManagementForbiddenException::class, OrganizationNotFoundException::class, OrganizationNotFoundException::class], $refused);
        self::assertSame([], $this->invoices->all());
    }

    #[DataProvider('managers')]
    public function test_it_changes_a_manual_expense_in_one_save(string $actor): void
    {
        $invoice = PurchaseInvoiceMother::manual();
        $this->invoices->add($invoice);

        $this->change($this->user($actor), $invoice->getId()->toRfc4122(), sellerName: 'Hala Targowa', grossTotal: 6_000, vatLines: []);

        self::assertSame('Hala Targowa', $invoice->getSellerName());
        self::assertSame(6_000, $invoice->getGrossTotal());
        self::assertSame([], $invoice->getVatLines());
        self::assertSame(1, $this->invoices->saveCallCount);
    }

    public function test_it_refuses_new_details_for_an_imported_expense(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $this->invoices->add($invoice);

        try {
            $this->change($this->owner, $invoice->getId()->toRfc4122(), sellerName: 'Someone else');
            self::fail('Read-only expected.');
        } catch (PurchaseInvoiceReadOnlyException) {
        }

        self::assertSame('Hurtownia Napojów', $invoice->getSellerName());
        self::assertSame(0, $this->invoices->saveCallCount);
    }

    public function test_it_refuses_changing_an_unknown_expense(): void
    {
        $this->expectException(PurchaseInvoiceNotFoundException::class);

        $this->change($this->owner, Uuid::v7()->toRfc4122(), sellerName: 'Hala Targowa');
    }

    public function test_it_refuses_changing_to_an_employee(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        $this->change($this->employee, Uuid::v7()->toRfc4122());
    }

    #[DataProvider('managers')]
    public function test_it_deletes_a_manual_expense(string $actor): void
    {
        $invoice = PurchaseInvoiceMother::manual();
        $this->invoices->add($invoice);

        $this->delete($this->user($actor), $invoice->getId()->toRfc4122());

        self::assertSame([], $this->invoices->all());
    }

    public function test_it_refuses_deleting_an_imported_or_unknown_expense_or_to_an_employee(): void
    {
        $imported = PurchaseInvoiceMother::imported();
        $this->invoices->add($imported);

        $refused = [];
        foreach ([[$this->owner, $imported->getId()->toRfc4122()], [$this->owner, 'not-a-uuid'], [$this->employee, $imported->getId()->toRfc4122()]] as [$user, $invoiceId]) {
            try {
                $this->delete($user, $invoiceId);
            } catch (PurchaseInvoiceReadOnlyException|PurchaseInvoiceNotFoundException|ExpensesManagementForbiddenException $e) {
                $refused[] = $e::class;
            }
        }

        self::assertSame([PurchaseInvoiceReadOnlyException::class, PurchaseInvoiceNotFoundException::class, ExpensesManagementForbiddenException::class], $refused);
        self::assertSame([$imported], $this->invoices->all());
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    /** @param list<array{rate: string, net: int, vat: int, vatInTaxCurrency: ?int}> $vatLines */
    private function enter(
        User $user,
        string $invoiceId,
        ?string $currency = 'PLN',
        string $kind = 'regular',
        string $issueDate = '2026-11-14',
        array $vatLines = [['rate' => '8', 'net' => 5_000, 'vat' => 400, 'vatInTaxCurrency' => null]],
    ): void {
        $handler = new EnterPurchaseInvoiceHandler($this->invoices, $this->queryBus, new MockClock(self::NOW));

        $handler(new EnterPurchaseInvoiceCommand(
            $this->organization->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $invoiceId,
            null,
            null,
            'Targ Rybny',
            $issueDate,
            $kind,
            $currency,
            5_000,
            400,
            5_400,
            $vatLines,
        ));
    }

    /** @param list<array{rate: string, net: int, vat: int, vatInTaxCurrency: ?int}> $vatLines */
    private function change(
        User $user,
        string $invoiceId,
        string $sellerName = 'Targ Rybny',
        int $grossTotal = 5_400,
        array $vatLines = [['rate' => '8', 'net' => 5_000, 'vat' => 400, 'vatInTaxCurrency' => null]],
    ): void {
        $handler = new ChangePurchaseInvoiceHandler($this->invoices, $this->queryBus);

        $handler(new ChangePurchaseInvoiceCommand(
            $this->organization->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $invoiceId,
            'PAR/0042',
            null,
            $sellerName,
            '2026-11-14',
            'regular',
            'PLN',
            5_000,
            400,
            $grossTotal,
            $vatLines,
        ));
    }

    private function delete(User $user, string $invoiceId): void
    {
        (new DeletePurchaseInvoiceHandler($this->invoices, $this->queryBus))(new DeletePurchaseInvoiceCommand($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $invoiceId));
    }
}
