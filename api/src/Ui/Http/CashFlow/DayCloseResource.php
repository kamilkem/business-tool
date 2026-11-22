<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\ListDayCloses\ListDayClosesQuery;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\CashFlow\Provider\DayCloseCollectionProvider;
use Ui\Http\CashFlow\Provider\DayCloseProvider;

/**
 * An end-of-day close of the organization selected by the X-Organization-Id header, with what the
 * ledger expected (ADR 0033 §6), for owners and managers: employees see only the close they make, in
 * the response to POST /day-closes (403 here, `cash_flow.access_forbidden.to_read_day_closes`).
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
        );
    }
}
