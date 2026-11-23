<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\ListRevenueEntryChanges\ListRevenueEntryChangesQuery;
use App\Income\Application\Query\ListRevenueEntryChanges\RevenueEntryChangeView;
use Ui\Http\Income\Provider\RevenueEntryChangeCollectionProvider;

/**
 * One write of a revenue entry in the organization selected by the X-Organization-Id header, kept for
 * good (ADR 0034 §4): who, when, and what the entry held before and after. Deleted entries keep their
 * history. Owners and managers only.
 */
#[ApiResource(
    shortName: 'RevenueEntryChange',
    operations: [
        new GetCollection(
            uriTemplate: '/revenue-entry-changes',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'The change history of one revenue entry, or of the entries dated in a period, newest first.',
                description: 'Owners and managers only (403 otherwise). Give entryId (also of a deleted entry), or from and to (business dates of the entries, inclusive, at most '.ListRevenueEntryChangesQuery::MAX_DAYS.' days), or all three; otherwise 422. History starts with the first write after it was introduced.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('Neither entryId nor a period is given, a date is invalid, to is before from, or the period is too long.'),
                ],
            ),
            queryParameterValidationEnabled: false,
            provider: RevenueEntryChangeCollectionProvider::class,
            parameters: [
                'entryId' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only this entry\'s changes.'),
                'from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'First business date, inclusive (YYYY-MM-DD).'),
                'to' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Last business date, inclusive (YYYY-MM-DD).'),
            ],
        ),
    ],
)]
final readonly class RevenueEntryChangeResource
{
    public function __construct(
        public string $id,
        /** The entry, which may since have been deleted. */
        public string $entryId,
        public string $registerId,
        /** The register's name at the time. */
        public string $registerName,
        /** YYYY-MM-DD */
        public string $businessDate,
        public string $currency,
        /** `created` and `changed` by hand, `deleted` by hand or with its close, `booked` by an end-of-day close. */
        public string $kind,
        /** The end-of-day close the write came from; null for a write by hand. */
        public ?string $dayCloseId,
        /** Null for a created entry, and for one a close booked new. */
        public ?RevenueEntryChangeSnapshot $before,
        /** Null for a deleted entry. */
        public ?RevenueEntryChangeSnapshot $after,
        /** Who made the change, a user id (GET /members). */
        public string $changedBy,
        public \DateTimeImmutable $changedAt,
    ) {
    }

    public static function fromView(RevenueEntryChangeView $view): self
    {
        return new self(
            id: $view->id,
            entryId: $view->entryId,
            registerId: $view->registerId,
            registerName: $view->registerName,
            businessDate: $view->businessDate,
            currency: $view->currency,
            kind: $view->kind,
            dayCloseId: $view->dayCloseId,
            before: RevenueEntryChangeSnapshot::of($view->before),
            after: RevenueEntryChangeSnapshot::of($view->after),
            changedBy: $view->changedBy,
            changedAt: $view->changedAt,
        );
    }
}
