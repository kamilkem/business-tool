<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Payroll\Processor\DeleteDayRateProcessor;
use Ui\Http\Payroll\Processor\SetDayRateProcessor;
use Ui\Http\Payroll\Processor\UpdateDayRateProcessor;
use Ui\Http\Payroll\Provider\DayRateCollectionProvider;
use Ui\Http\Payroll\Provider\DayRateProvider;
use Ui\Http\Payroll\Provider\UpdateDayRateInputProvider;

/**
 * A member's rate for one business date in the organization selected by the X-Organization-Id
 * header (New Year's Eve), replacing their hourly rate for the shifts that started on that date
 * (ADR 0027 §3). Who may see and change day rates is as for hourly rates; a day rate on a date of a
 * locked period (an approved payroll month) cannot be added, changed or deleted (409).
 */
#[ApiResource(
    shortName: 'DayRate',
    operations: [
        new GetCollection(
            uriTemplate: '/day-rates',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the day rates of the selected organization.',
                description: 'By member, newest first. Owners and managers see everyone\'s, deactivated members\' too, and may narrow them to one member with userId; employees see their own only, and asking for someone else\'s is a 403. A userId that is not a member is a 404, and one that is not a UUID a 422.',
                responses: [
                    '403' => new Response('An employee asked for someone else\'s day rates.'),
                    '404' => new Response('The userId is not a member of the selected organization.'),
                    '422' => new Response('The userId is not a UUID.'),
                ],
            ),
            // ListRatesInput validates the parameter; the schema below only documents it.
            queryParameterValidationEnabled: false,
            provider: DayRateCollectionProvider::class,
            parameters: [
                'userId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only this member\'s day rates. Employees may pass only their own id.',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/day-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One day rate of the selected organization.',
                description: 'Owners and managers see anyone\'s; employees their own only (403 otherwise). A day rate that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('An employee asked for someone else\'s day rate.'),
                    '404' => new Response('The day rate is not one of the selected organization\'s.'),
                ],
            ),
            provider: DayRateProvider::class,
        ),
        new Post(
            uriTemplate: '/day-rates',
            openapi: new Operation(
                summary: 'Gives a member a rate for one business date.',
                description: 'Owners and managers only (403 otherwise), for any member, active or deactivated, themselves included; a userId that is not a member is a 404. The currency is the organization\'s. One day rate per member and date (409); a date in a locked period is a 409.',
                responses: [
                    '201' => new Response('The new day rate.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The userId is not a member of the selected organization.'),
                    '409' => new Response('The member has a day rate on that date, or the date is locked.'),
                    '422' => new Response('A field is missing or invalid.'),
                ],
            ),
            input: SetDayRateInput::class,
            read: false,
            processor: SetDayRateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/day-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Corrects a day rate\'s date or amount.',
                description: 'Owners and managers only (403 otherwise); a day rate that is not the selected organization\'s is a 404. The currency becomes the organization\'s. Another day rate of the member on the new date is a 409, and so is an old or new date in a locked period. Changing nothing is a 200.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The day rate is not one of the selected organization\'s.'),
                    '409' => new Response('Another day rate of the member is on the new date, or the old or new date is locked.'),
                    '422' => new Response('A field is invalid.'),
                ],
            ),
            input: UpdateDayRateInput::class,
            provider: UpdateDayRateInputProvider::class,
            processor: UpdateDayRateProcessor::class,
        ),
        new Delete(
            uriTemplate: '/day-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a day rate.',
                description: 'Owners and managers only (403 otherwise); a day rate that is not the selected organization\'s is a 404. The date falls back to the member\'s hourly rate. A date in a locked period is a 409.',
                responses: [
                    '204' => new Response('The day rate was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The day rate is not one of the selected organization\'s.'),
                    '409' => new Response('The date is locked.'),
                ],
            ),
            read: false,
            processor: DeleteDayRateProcessor::class,
        ),
    ],
)]
final readonly class DayRateResource
{
    public function __construct(
        public string $id,
        /** The member the rate is for. */
        public string $userId,
        /** The business date whose shifts the rate pays (YYYY-MM-DD). */
        public string $date,
        /** Gross minor units per hour. */
        public int $amount,
        /** ISO 4217: the organization's when the day rate was set or last changed. */
        public string $currency,
        public \DateTimeImmutable $createdAt,
        /** When the day rate was last changed; null until then. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(DayRateView $view): self
    {
        return new self($view->id, $view->userId, $view->date, $view->amount, $view->currency, $view->createdAt, $view->updatedAt);
    }
}
