<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Application\Query\ListRevenueEntries\ListRevenueEntriesQuery;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Income\Processor\DeleteRevenueEntryProcessor;
use Ui\Http\Income\Processor\RecordRevenueEntryProcessor;
use Ui\Http\Income\Processor\UpdateRevenueEntryProcessor;
use Ui\Http\Income\Provider\RevenueEntryCollectionProvider;
use Ui\Http\Income\Provider\RevenueEntryProvider;
use Ui\Http\Income\Provider\UpdateRevenueEntryInputProvider;

/**
 * What one register took on one business date, from its daily report, in the organization selected
 * by the X-Organization-Id header (ADR 0022). Owners and managers only: anyone else gets a 403. An
 * end-of-day close of any member books its register's entry (ADR 0033 §9; `dayCloseId`).
 * Amounts are integer minor units of `currency`, the organization's when the entry was recorded
 * (ADR 0010). The totals are computed; the VAT totals need not match the payments total.
 */
#[ApiResource(
    shortName: 'RevenueEntry',
    operations: [
        new Get(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One revenue entry of the selected organization.',
                description: 'Owners and managers only (403 otherwise). An entry that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                ],
            ),
            provider: RevenueEntryProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/revenue-entries',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the revenue entries of the selected organization from one business date to another.',
                description: 'Owners and managers only (403 otherwise). By business date, then by register name; entries of deactivated registers are included. registerId narrows the list to one register of the selected organization (404 otherwise). Invalid dates, to before from and periods longer than '.ListRevenueEntriesQuery::MAX_DAYS.' days are a 422. GET /revenue-summary gives the totals.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The registerId is not a register of the selected organization.'),
                    '422' => new Response('A date is missing or invalid, to is before from, the period is too long, or registerId is not a UUID.'),
                ],
            ),
            // ListRevenueEntriesInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: RevenueEntryCollectionProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First business date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last business date, inclusive (YYYY-MM-DD); at most '.ListRevenueEntriesQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
                'registerId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only this register\'s entries.',
                ),
            ],
        ),
        new Post(
            uriTemplate: '/revenue-entries',
            openapi: new Operation(
                summary: "Records a register's daily report for a business date.",
                description: 'Owners and managers only (403 otherwise). One entry per register and business date: a second one is a 409 (correct the existing entry instead). The register must be the selected organization\'s (404) and active (422); the business date may not be after today in the organization\'s time zone (422). At least one payment line, each method once, amounts non-negative integer minor units (422); the methods are the organization\'s (GET /payment-methods, 422 otherwise) and active (422). VAT lines are optional, use the rates of GET /vat-rates, each once, with 0 <= vat <= gross and vat 0 at a 0% or exempt rate (422); an organization whose country has no rates takes none (422). The currency is the organization\'s. A business date in a closed month is a 409 (ADR 0034). Every write is kept in the change history (GET /revenue-entry-changes).',
                responses: [
                    '201' => new Response('The recorded entry.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The register is not one of the selected organization\'s.'),
                    '409' => new Response('The register already has an entry for the business date, or the business date is in a closed month.'),
                    '422' => new Response('A field is missing or invalid, the register is deactivated, the date is in the future, or a line breaks a rule.'),
                ],
            ),
            input: RecordRevenueEntryInput::class,
            read: false,
            processor: RecordRevenueEntryProcessor::class,
        ),
        new Patch(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: "Corrects an entry's payment lines, VAT lines and note.",
                description: 'Owners and managers only (403 otherwise); an entry that is not the selected organization\'s is a 404. A list that is sent replaces the current one whole: `vatLines: []` (or null) removes the breakdown. The register and the business date do not change: delete the entry and record it again. An entry of a deactivated register may be corrected, and may keep the lines of deactivated payment methods it already has. The rules of POST apply to the lines (422). A change records who made it and when (updatedBy, updatedAt); sending what the entry already has changes nothing. An entry booked from an end-of-day close (dayCloseId) keeps the payment lines the close wrote: changing them is a 409, while its VAT lines and note may be corrected. An entry of a closed month cannot change at all (409), even with the values it has.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                    '409' => new Response('The entry\'s month is closed, or the entry is booked from an end-of-day close and the payment lines differ from the close\'s.'),
                    '422' => new Response('A field is invalid or a line breaks a rule.'),
                ],
            ),
            input: UpdateRevenueEntryInput::class,
            provider: UpdateRevenueEntryInputProvider::class,
            processor: UpdateRevenueEntryProcessor::class,
        ),
        new Delete(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a revenue entry.',
                description: 'Owners and managers only (403 otherwise); an entry that is not the selected organization\'s is a 404. The entry and its lines are removed. An entry booked from an end-of-day close (dayCloseId) goes only with its close (409), and an entry of a closed month stays (409). The change history keeps the deleted entry (GET /revenue-entry-changes).',
                responses: [
                    '204' => new Response('The entry was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                    '409' => new Response('The entry\'s month is closed, or the entry is booked from an end-of-day close.'),
                ],
            ),
            read: false,
            processor: DeleteRevenueEntryProcessor::class,
        ),
    ],
)]
final readonly class RevenueEntryResource
{
    /**
     * @param list<RevenueEntryPayment> $payments
     * @param list<RevenueEntryVatLine> $vatLines
     */
    public function __construct(
        public string $id,
        public string $registerId,
        public string $registerName,
        /** The business date the daily report covers, `YYYY-MM-DD`. */
        public string $businessDate,
        /** ISO 4217 code of the amounts: the organization's currency when the entry was recorded. */
        public string $currency,
        /** One line per payment method used, in the organization's order, deactivated methods last. */
        public array $payments,
        /** The VAT breakdown as printed, highest rate first, exempt last; empty when not entered. */
        public array $vatLines,
        /** The sum of the payment lines, in minor units. */
        public int $paymentsTotal,
        /** The sum of the VAT lines' gross amounts, in minor units; 0 without a breakdown. */
        public int $vatGrossTotal,
        /** The sum of the VAT lines' VAT, in minor units; 0 without a breakdown. */
        public int $vatTotal,
        public ?string $note,
        /**
         * The end-of-day close (GET /day-closes/{id}) the entry is booked from; null for a manual
         * entry. Its payment lines change only through the close; its VAT lines and note stay editable.
         */
        public ?string $dayCloseId,
        /** Whether the entry's month is closed (GET /revenue-months/{month}): it cannot change until an owner reopens it (ADR 0034). */
        public bool $monthClosed,
        /** Who recorded the entry: an owner or manager, or the member of any role who closed the day. */
        public string $createdBy,
        public \DateTimeImmutable $createdAt,
        /** Who changed the entry last (an owner or manager, or the member whose close took it over); null until the first change. */
        public ?string $updatedBy,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(RevenueEntryView $view): self
    {
        return new self(
            id: $view->id,
            registerId: $view->registerId,
            registerName: $view->registerName,
            businessDate: $view->businessDate,
            currency: $view->currency,
            payments: array_map(
                RevenueEntryPayment::fromView(...),
                $view->payments,
            ),
            vatLines: array_map(
                static fn (RevenueVatLineView $line): RevenueEntryVatLine => new RevenueEntryVatLine($line->rate, $line->gross, $line->vat, $line->net),
                $view->vatLines,
            ),
            paymentsTotal: $view->paymentsTotal,
            vatGrossTotal: $view->vatGrossTotal,
            vatTotal: $view->vatTotal,
            note: $view->note,
            dayCloseId: $view->dayCloseId,
            monthClosed: $view->monthClosed,
            createdBy: $view->createdBy,
            createdAt: $view->createdAt,
            updatedBy: $view->updatedBy,
            updatedAt: $view->updatedAt,
        );
    }
}
