<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Expenses\Processor\UpdateExpenseProcessor;
use Ui\Http\Expenses\Provider\ExpenseDocumentProvider;
use Ui\Http\Expenses\Provider\ExpenseProvider;
use Ui\Http\Expenses\Provider\UpdateExpenseInputProvider;

/**
 * A purchase invoice of the organization selected by the X-Organization-Id header: one expense,
 * imported (e.g. from KSeF) or, later, entered by hand (ADR 0024). Owners and managers only: anyone
 * else gets a 403. Amounts are integer minor units of `currency` (ADR 0010), stored as printed:
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
                summary: 'One expense of the selected organization, with its VAT lines.',
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
        new Patch(
            uriTemplate: '/expenses/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Sets or clears the category of an expense.',
                description: 'Owners and managers only (403 otherwise); an expense that is not the selected organization\'s is a 404. categoryId must be a category of the selected organization (404) that is not archived (422), unless it is the expense\'s category already; null clears it. Any expense can be categorized, imported or not.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The expense or the category is not one of the selected organization\'s.'),
                    '422' => new Response('categoryId is not a UUID, or the category is archived.'),
                ],
            ),
            input: UpdateExpenseInput::class,
            provider: UpdateExpenseInputProvider::class,
            processor: UpdateExpenseProcessor::class,
        ),
    ],
)]
final readonly class ExpenseResource
{
    /** @param list<ExpenseVatLine> $vatLines */
    public function __construct(
        public string $id,
        /** Where the expense came from: `ksef` (imported) or `manual`. */
        public string $source,
        /** The invoice's id in its source (for KSeF, the KSeF number); null for a manual expense. */
        public ?string $externalId,
        public string $invoiceNumber,
        public string $sellerTaxId,
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
        public ?string $categoryId,
        /** The category's name (it may be archived); null when uncategorized. */
        public ?string $categoryName,
        /** True when the original document is stored (GET /expenses/{id}/document). */
        public bool $hasDocument,
        /** What the document is, as its importer named it (for KSeF, the FA form code); null without a document. */
        public ?string $documentFormat,
        /** When the expense was imported or entered. */
        public \DateTimeImmutable $createdAt,
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
            categoryId: $view->categoryId,
            categoryName: $view->categoryName,
            hasDocument: null !== $view->documentFormat,
            documentFormat: $view->documentFormat,
            createdAt: $view->createdAt,
        );
    }
}
