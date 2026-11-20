<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesQuery;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceListView;
use Ui\Http\Expenses\Provider\ExpenseListProvider;

/**
 * The expenses of the organization selected by the X-Organization-Id header issued in a period of
 * business dates (ADR 0017), with their totals per currency. Owners and managers only.
 */
#[ApiResource(
    shortName: 'ExpenseList',
    operations: [
        new Get(
            uriTemplate: '/expenses',
            openapi: new Operation(
                summary: 'Lists the expenses of the selected organization issued from one date to another, with totals per currency.',
                description: 'Owners and managers only (403 otherwise). Newest issue date first, then by seller name and invoice number. Filters: categoryId (a category of the selected organization, archived ones included; 404 otherwise) or uncategorized=true, not both (422); seller matches a part of the seller\'s name (case-insensitive) or tax ID (spaces and dashes in the search ignored). Totals add up the listed expenses per currency: `totals[0]` is always the organization\'s current currency, also when nothing is listed (all 0), and other currencies follow by code; corrections count with their negative amounts. Invalid dates, to before from and periods longer than '.ListPurchaseInvoicesQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The categoryId is not a category of the selected organization.'),
                    '422' => new Response('A date is missing or invalid, to is before from, the period is too long, or a filter is invalid.'),
                ],
            ),
            // ListExpensesInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: ExpenseListProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First issue date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last issue date, inclusive (YYYY-MM-DD); at most '.ListPurchaseInvoicesQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
                'categoryId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only expenses of this category.',
                ),
                'uncategorized' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Only expenses without a category.',
                ),
                'seller' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'A part of the seller\'s name or tax ID.',
                ),
            ],
        ),
    ],
)]
final readonly class ExpenseListResource
{
    /**
     * @param list<ExpenseResource>      $items
     * @param list<ExpenseCurrencyTotal> $totals
     */
    public function __construct(
        /** First issue date of the period, `YYYY-MM-DD`. */
        public string $from,
        /** Last issue date of the period, inclusive, `YYYY-MM-DD`. */
        public string $to,
        /** The expenses, newest issue date first. */
        #[ApiProperty(readableLink: true)]
        public array $items,
        /** Totals of the listed expenses per currency: the organization's current currency first, always present. */
        public array $totals,
    ) {
    }

    public static function fromView(PurchaseInvoiceListView $view): self
    {
        return new self(
            $view->from,
            $view->to,
            array_map(ExpenseResource::fromView(...), $view->items),
            array_map(ExpenseCurrencyTotal::fromView(...), $view->totals),
        );
    }
}
