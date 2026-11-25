<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoices;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Expenses\Domain\Exception\ExpensePeriodTooLongException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Adds up the listed invoices in PHP: the list already holds them with their VAT lines (a small
 * business receives a few hundred a year), and the totals then always match the items.
 */
final readonly class ListPurchaseInvoicesHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private PurchaseInvoiceDocumentRepositoryInterface $documents,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws TimeException                        when $from or $to is not a date, or $to is before $from
     * @throws ExpensePeriodTooLongException        when the period is longer than ListPurchaseInvoicesQuery::MAX_DAYS
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     */
    public function __invoke(ListPurchaseInvoicesQuery $query): ?PurchaseInvoiceListView
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListPurchaseInvoicesQuery::MAX_DAYS) {
            throw ExpensePeriodTooLongException::of($period, ListPurchaseInvoicesQuery::MAX_DAYS);
        }

        if (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }
        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($query->organizationId, $query->actorId));
        if (null === $organization) {
            return null;
        }

        $invoices = $this->invoices->findByFilter(new PurchaseInvoiceFilter($period, $query->seller));
        $formats = $this->documents->findFormatsByInvoiceIds(array_map(static fn (PurchaseInvoice $invoice): Uuid => $invoice->getId(), $invoices));

        return new PurchaseInvoiceListView(
            $period->first->toString(),
            $period->last->toString(),
            array_map(
                static fn (PurchaseInvoice $invoice): PurchaseInvoiceView => PurchaseInvoiceView::of($invoice, $formats[$invoice->getId()->toRfc4122()] ?? null),
                $invoices,
            ),
            self::totals($organization->currency, $invoices),
        );
    }

    /**
     * @param list<PurchaseInvoice> $invoices
     *
     * @return list<PurchaseInvoiceCurrencyTotalView> the organization's currency first, always; others by code
     */
    private static function totals(string $organizationCurrency, array $invoices): array
    {
        $byCurrency = [$organizationCurrency => []];
        foreach ($invoices as $invoice) {
            $byCurrency[$invoice->getCurrency()][] = $invoice;
        }
        $others = array_diff(array_map(strval(...), array_keys($byCurrency)), [$organizationCurrency]);
        sort($others);

        return array_map(
            static fn (string $currency): PurchaseInvoiceCurrencyTotalView => PurchaseInvoiceCurrencyTotalView::of($currency, $byCurrency[$currency]),
            [$organizationCurrency, ...$others],
        );
    }
}
