<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\ListDayCloses\ListDayClosesQuery;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\CashFlow\Processor\CorrectDayCloseProcessor;
use Ui\Http\CashFlow\Processor\DeleteDayCloseProcessor;
use Ui\Http\CashFlow\Provider\DayCloseCollectionProvider;
use Ui\Http\CashFlow\Provider\DayCloseProvider;

/**
 * An end-of-day close of the organization selected by the X-Organization-Id header, with what the
 * ledger expected (ADR 0033 §6), for owners and managers: employees see only the close they make, in
 * the response to POST /day-closes (403 here, `cash_flow.access_forbidden.to_read_day_closes`).
 * Owners and managers correct and delete closes (§7); employees cannot redo one.
 */
#[ApiResource(
    shortName: 'DayClose',
    operations: [
        new GetCollection(
            uriTemplate: '/day-closes',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the end-of-day closes of the selected organization from one business date to another.',
                description: 'Owners and managers only (403 otherwise). The closes of the dates within the period, latest first. Invalid dates, to before from and periods longer than '.ListDayClosesQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A date is missing or invalid, to is before from, or the period is too long.'),
                ],
            ),
            // ListDayClosesInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: DayCloseCollectionProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First business date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last business date, inclusive (YYYY-MM-DD); at most '.ListDayClosesQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/day-closes/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One end-of-day close of the selected organization.',
                description: 'Owners and managers only (403 otherwise). A close that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The close is not one of the selected organization\'s.'),
                ],
            ),
            provider: DayCloseProvider::class,
        ),
        new Put(
            uriTemplate: '/day-closes/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Corrects an end-of-day close of the selected organization.',
                description: 'Owners and managers only (403 otherwise, before the body is validated). The body replaces the close\'s counts, deposits, non-cash amounts and register; the business date never changes (delete the close and close the date again instead). It covers what the close covered: every storage of the close once (in the mode it had at the close; a counted storage\'s missing denomination is zero) and every non-cash payment method of the close once, deactivated ones included; a denomination is offered when it is active or already in the close. The register is the close\'s own or an active one. The expected openings and the cash income are computed again, the revenue entry booked from the close follows (another register: the old entry is removed and the new register\'s is booked), and so does the next close of each storage that counted it. A correction that would take a deposit storage below zero pieces of a denomination is a 409.',
                responses: [
                    '200' => new Response('The close as corrected.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The close is not one of the selected organization\'s.'),
                    '409' => new Response('A deposit storage would go below zero pieces of a denomination.'),
                    '422' => new Response('The body is invalid, or does not cover what the close covers.'),
                ],
            ),
            input: CorrectDayCloseInput::class,
            // The provider refuses employees (403) and unknown closes (404) before the body is read.
            read: true,
            provider: DayCloseProvider::class,
            processor: CorrectDayCloseProcessor::class,
        ),
        new Delete(
            uriTemplate: '/day-closes/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes an end-of-day close of the selected organization.',
                description: 'Owners and managers only (403 otherwise). It removes the close\'s ledger records and the revenue entry booked from it (VAT breakdown and note included); its cash then counts in the next close of each storage, which is computed again with its revenue entry. A delete that would take a deposit storage below zero pieces of a denomination is a 409. The date may then be closed again only when no later date is closed.',
                responses: [
                    '204' => new Response('The close is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The close is not one of the selected organization\'s.'),
                    '409' => new Response('A deposit storage would go below zero pieces of a denomination.'),
                ],
            ),
            provider: DayCloseProvider::class,
            processor: DeleteDayCloseProcessor::class,
        ),
    ],
)]
final readonly class DayCloseResource
{
    /**
     * @param list<DayCloseStorageItem> $storages by the storages' names
     * @param list<DayClosePaymentLine> $payments in the organization's order of payment methods
     */
    public function __construct(
        public string $id,
        /** The business date closed (YYYY-MM-DD). */
        public string $date,
        /** The register the close books into. */
        public string $registerId,
        /** The register's current name; null when it is no longer offered. */
        public ?string $registerName,
        /** ISO 4217. */
        public string $currency,
        /** Minor units: Σ counted storages (total − expected opening) + Σ deposits. Negative for a shortage. */
        public int $cashIncome,
        /** Whether the cash income is negative: a shortage, or a withdrawal nobody recorded, to review. */
        public bool $shortage,
        public array $storages,
        public array $payments,
        /** The member who closed, of any role. */
        public string $closedBy,
        public string $closedByFirstName,
        public string $closedByLastName,
        /** The server's time of the close: where it sits in the cash ledger. */
        public \DateTimeImmutable $closedAt,
        /** The owner or manager who corrected the close last; null while nobody did. */
        public ?string $updatedBy,
        public ?string $updatedByFirstName,
        public ?string $updatedByLastName,
        /** When the close was corrected last; null while nobody did. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(DayCloseView $view): self
    {
        return new self(
            id: $view->id,
            date: $view->date,
            registerId: $view->registerId,
            registerName: $view->registerName,
            currency: $view->currency,
            cashIncome: $view->cashIncome,
            shortage: $view->shortage,
            storages: array_map(DayCloseStorageItem::fromView(...), $view->storages),
            payments: array_map(DayClosePaymentLine::fromView(...), $view->payments),
            closedBy: $view->closedById,
            closedByFirstName: $view->closedByFirstName,
            closedByLastName: $view->closedByLastName,
            closedAt: $view->closedAt,
            updatedBy: $view->updatedById,
            updatedByFirstName: $view->updatedByFirstName,
            updatedByLastName: $view->updatedByLastName,
            updatedAt: $view->updatedAt,
        );
    }
}
