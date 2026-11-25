<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersHandler;
use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersQuery;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetPurchaseInvoiceNumbersHandler::class)]
final class GetPurchaseInvoiceNumbersHandlerTest extends TestCase
{
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private Organization $organization;
    private GetPurchaseInvoiceNumbersHandler $handler;

    protected function setUp(): void
    {
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext->switchTo($this->organization);
        $this->handler = new GetPurchaseInvoiceNumbersHandler($this->invoices, $this->tenantContext);
    }

    public function test_it_gives_the_numbers_of_the_invoices_that_have_one(): void
    {
        $first = PurchaseInvoiceMother::imported(invoiceNumber: 'FV/1/2026');
        $second = PurchaseInvoiceMother::imported(invoiceNumber: 'FV/2/2026');
        $receipt = PurchaseInvoiceMother::manual(invoiceNumber: null);
        foreach ([$first, $second, $receipt] as $invoice) {
            $this->invoices->add($invoice);
        }

        $numbers = $this->ask([$first->getId()->toRfc4122(), $receipt->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), 'not-a-uuid', $first->getId()->toRfc4122()]);

        self::assertSame([$first->getId()->toRfc4122() => 'FV/1/2026'], $numbers);
    }

    public function test_it_answers_nothing_for_no_invoices_or_another_organization(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $this->invoices->add($invoice);

        self::assertSame([], $this->ask([]));
        self::assertSame([], (new GetPurchaseInvoiceNumbersHandler($this->invoices, $this->tenantContext))(new GetPurchaseInvoiceNumbersQuery(Uuid::v7()->toRfc4122(), [$invoice->getId()->toRfc4122()])));
        $this->tenantContext->reset();
        self::assertSame([], $this->ask([$invoice->getId()->toRfc4122()]));
    }

    public function test_it_refuses_more_invoices_than_the_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ask(array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(0, GetPurchaseInvoiceNumbersQuery::MAX_INVOICES)));
    }

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, string>
     */
    private function ask(array $invoiceIds): array
    {
        return ($this->handler)(new GetPurchaseInvoiceNumbersQuery($this->organization->getId()->toRfc4122(), $invoiceIds));
    }
}
