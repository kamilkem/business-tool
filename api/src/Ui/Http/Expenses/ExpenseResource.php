<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Expenses\Processor\DeleteExpenseProcessor;
use Ui\Http\Expenses\Processor\EnterExpenseProcessor;
use Ui\Http\Expenses\Processor\UpdateExpenseProcessor;
use Ui\Http\Expenses\Provider\ExpenseDocumentProvider;
use Ui\Http\Expenses\Provider\ExpenseProvider;
use Ui\Http\Expenses\Provider\UpdateExpenseInputProvider;

/**
 * A purchase invoice of the organization selected by the X-Organization-Id header: one expense,
 * imported (e.g. from KSeF) or entered by hand (source `manual`; ADR 0024, #54). Owners and managers
 * only: anyone else gets a 403. Only a manual expense can be edited or deleted. Amounts are integer minor units of `currency` (ADR 0010), stored as printed:
 * negative ones for corrections, and totals not checked against the VAT lines. GET /expenses lists
 * them with totals.
 */
#[ApiResource(
    shortName: 'Expense',
    operations: [
        new Get(
            uriTemplate: '/expenses/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One expense of the selected organization, with its VAT lines and items.',
                description: 'Owners and managers only (403 otherwise). An expense that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The expense is not one of the selected organization\'s.'),
                ],
            ),
            provider: ExpenseProvider::class,
        ),
        new Get(
            uriTemplate: '/expenses/{id}/document',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            outputFormats: ['xml' => ['application/xml'], 'binary' => ['application/octet-stream']],
            openapi: new Operation(
                summary: 'The original document of an expense, as received.',
                description: 'Owners and managers only (403 otherwise). The stored copy, unchanged (for an imported e-invoice, its XML), never fetched from the source again (ADR 0023 §6, ADR 0024). Served as application/xml when it is XML, as application/octet-stream otherwise, as an attachment; the ETag is its SHA-256. An expense without a document (entered by hand) or that is not the selected organization\'s is a 404.',
                responses: [
                    '200' => new Response('The document.', new \ArrayObject([
                        'application/xml' => ['schema' => ['type' => 'string']],
                        'application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                    ])),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The expense has no document, or is not one of the selected organization\'s.'),
                ],
            ),
            provider: ExpenseDocumentProvider::class,
        ),
        new Post(
            uriTemplate: '/expenses',
            openapi: new Operation(
                summary: 'Enters an expense by hand: a cash receipt, a foreign invoice.',
                description: 'Owners and managers only (403 otherwise). The expense gets the source `manual`. The invoice number and the seller tax ID are optional; the kind defaults to `regular` and the currency to the organization\'s, which is also the tax currency. Amounts are integer minor units, stored as given (totals are not checked against each other or the lines). VAT lines are optional, use the rates of GET /vat-rates, each once, with vat 0 at a 0% or exempt rate, and give vatInTaxCurrency exactly when the currency is not the organization\'s (422).',
                responses: [
                    '201' => new Response('The entered expense.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A field is missing or invalid, or a VAT line breaks a rule.'),
                ],
            ),
            input: EnterExpenseInput::class,
            read: false,
            processor: EnterExpenseProcessor::class,
        ),
        new Patch(
            uriTemplate: '/expenses/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Edits a manual expense.',
                description: 'Owners and managers only (403 otherwise); an expense that is not the selected organization\'s is a 404. A manual expense takes any of the fields of POST /expenses; a `vatLines` list replaces the current one whole (null or [] removes it), and a blank or null invoiceNumber or sellerTaxId removes it. The tax currency does not change. An imported expense says what its source says: sending back its own values changes nothing, and any other value is a 409.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The expense is not one of the selected organization\'s.'),
                    '409' => new Response('The expense is imported and cannot be changed.'),
                    '422' => new Response('A field is invalid, or a VAT line breaks a rule.'),
                ],
            ),
            input: UpdateExpenseInput::class,
            provider: UpdateExpenseInputProvider::class,
            processor: UpdateExpenseProcessor::class,
        ),
        new Delete(
            uriTemplate: '/expenses/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a manual expense.',
                description: 'Owners and managers only (403 otherwise); an expense that is not the selected organization\'s is a 404. The expense and its VAT lines are removed and no trace is kept. An imported expense cannot be deleted (409).',
                responses: [
                    '204' => new Response('The expense was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The expense is not one of the selected organization\'s.'),
                    '409' => new Response('The expense is imported and cannot be deleted.'),
                ],
            ),
            read: false,
            processor: DeleteExpenseProcessor::class,
        ),
    ],
)]
final readonly class ExpenseResource
{
    /**
     * @param list<ExpenseVatLine> $vatLines
     * @param list<ExpenseItem>    $items
     */
    public function __construct(
        public string $id,
        /** Where the expense came from: `ksef` (imported) or `manual`. */
        public string $source,
        /** The invoice's id in its source (for KSeF, the KSeF number); null for a manual expense. */
        public ?string $externalId,
        /** Null for a manual expense without one (a receipt). */
        public ?string $invoiceNumber,
        /** Null for a manual expense whose seller has none. */
        public ?string $sellerTaxId,
        public string $sellerName,
        /** The issue date, `YYYY-MM-DD`. */
        public string $issueDate,
        /** `regular`, `correction`, `advance`, `settlement`, `simplified`, `advance_correction` or `settlement_correction`. */
        public string $kind,
        /** For a correction: the corrected invoice's id in the same source, when the correction gives it. */
        public ?string $correctedExternalId,
        /** ISO 4217 code of the amounts. */
        public string $currency,
        /** ISO 4217 code VAT is settled in: the organization's currency when the expense was stored. */
        public string $taxCurrency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        /** The VAT breakdown as printed, highest rate first, exempt last; may be empty. */
        public array $vatLines,
        /** True when the original document is stored (GET /expenses/{id}/document). */
        public bool $hasDocument,
        /** What the document is, as its importer named it (for KSeF, the FA form code); null without a document. */
        public ?string $documentFormat,
        /** When the expense was imported or entered. */
        public \DateTimeImmutable $createdAt,
        /** The items as printed, by position (ADR 0040); [] for a manual expense or one imported before items were kept. */
        public array $items,
    ) {
    }

    public static function fromView(PurchaseInvoiceView $view): self
    {
        return new self(
            id: $view->id,
            source: $view->source,
            externalId: $view->externalId,
            invoiceNumber: $view->invoiceNumber,
            sellerTaxId: $view->sellerTaxId,
            sellerName: $view->sellerName,
            issueDate: $view->issueDate,
            kind: $view->kind,
            correctedExternalId: $view->correctedExternalId,
            currency: $view->currency,
            taxCurrency: $view->taxCurrency,
            netTotal: $view->netTotal,
            vatTotal: $view->vatTotal,
            grossTotal: $view->grossTotal,
            vatLines: array_map(ExpenseVatLine::fromView(...), $view->vatLines),
            hasDocument: null !== $view->documentFormat,
            documentFormat: $view->documentFormat,
            createdAt: $view->createdAt,
            items: array_map(ExpenseItem::fromView(...), $view->items ?? []),
        );
    }
}
