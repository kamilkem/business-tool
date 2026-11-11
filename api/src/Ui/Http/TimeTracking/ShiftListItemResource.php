<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\TimeTracking\Application\Query\ListShifts\ListShiftsQuery;
use App\TimeTracking\Application\Query\ListShifts\ShiftListItemView;
use Ui\Http\TimeTracking\Provider\ShiftCollectionProvider;

/**
 * A shift in the list of shifts of the organization selected by the X-Organization-Id header, with
 * the person's name and the time worked. Dates are business dates in the organization's time zone
 * (ADR 0017); a shift belongs to the date it started on.
 */
#[ApiResource(
    shortName: 'ShiftListItem',
    operations: [
        new GetCollection(
            uriTemplate: '/shifts',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the shifts of the selected organization that started from one business date to another.',
                description: 'Newest first. A shift belongs to the business date (in the organization\'s time zone) it started on, also when it ends after midnight; open shifts are included. Owners and managers see everyone\'s shifts, deactivated members\' too, and may narrow them to one person with userId; employees see their own only, and asking for someone else\'s is a 403. A userId that is not a member is a 404. Invalid dates, to before from and periods longer than '.ListShiftsQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('An employee asked for someone else\'s shifts.'),
                    '404' => new Response('The userId is not a member of the selected organization.'),
                    '422' => new Response('A date is missing or invalid, to is before from, or the period is too long.'),
                ],
            ),
            // ListShiftsInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: ShiftCollectionProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First business date, inclusive (YYYY-MM-DD in the organization\'s time zone).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last business date, inclusive (YYYY-MM-DD in the organization\'s time zone); at most '.ListShiftsQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
                'userId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only this person\'s shifts. Employees may pass only their own id.',
                ),
            ],
        ),
    ],
)]
final readonly class ShiftListItemResource
{
    public function __construct(
        public string $id,
        /** The person who worked the shift. */
        public string $userId,
        public string $firstName,
        public string $lastName,
        /** The business date the shift belongs to: the date in the organization's time zone on which it started (YYYY-MM-DD). */
        public string $date,
        /** When the person clocked in (server time). */
        public \DateTimeImmutable $startedAt,
        /** When the person clocked out (server time); null while they are clocked in. */
        public ?\DateTimeImmutable $endedAt,
        /** Whole minutes from clock-in to clock-out; null while the shift is open. */
        public ?int $durationMinutes,
        public ?string $note,
    ) {
    }

    public static function fromView(ShiftListItemView $view): self
    {
        return new self(
            id: $view->id,
            userId: $view->userId,
            firstName: $view->firstName,
            lastName: $view->lastName,
            date: $view->date,
            startedAt: $view->startedAt,
            endedAt: $view->endedAt,
            durationMinutes: $view->durationMinutes,
            note: $view->note,
        );
    }
}
