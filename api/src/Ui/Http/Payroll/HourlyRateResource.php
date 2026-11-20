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
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Payroll\Processor\DeleteHourlyRateProcessor;
use Ui\Http\Payroll\Processor\SetHourlyRateProcessor;
use Ui\Http\Payroll\Processor\UpdateHourlyRateProcessor;
use Ui\Http\Payroll\Provider\HourlyRateCollectionProvider;
use Ui\Http\Payroll\Provider\HourlyRateProvider;
use Ui\Http\Payroll\Provider\UpdateHourlyRateInputProvider;

/**
 * A member's usual hourly rate in the organization selected by the X-Organization-Id header, in
 * effect from a business date until the day before their next rate (ADR 0027 §3). Owners and managers
 * manage everyone's, their own included; employees read their own only (403 otherwise). A change that
 * affects a date of a locked period (an approved payroll month) is a 409.
 */
#[ApiResource(
    shortName: 'HourlyRate',
    operations: [
        new GetCollection(
            uriTemplate: '/hourly-rates',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the hourly rates of the selected organization.',
                description: 'By member, newest first. Owners and managers see everyone\'s, deactivated members\' too, and may narrow them to one member with userId; employees see their own only, and asking for someone else\'s is a 403. A userId that is not a member is a 404, and one that is not a UUID a 422.',
                responses: [
                    '403' => new Response('An employee asked for someone else\'s rates.'),
                    '404' => new Response('The userId is not a member of the selected organization.'),
                    '422' => new Response('The userId is not a UUID.'),
                ],
            ),
            // ListRatesInput validates the parameter; the schema below only documents it.
            queryParameterValidationEnabled: false,
            provider: HourlyRateCollectionProvider::class,
            parameters: [
                'userId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only this member\'s rates. Employees may pass only their own id.',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/hourly-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One hourly rate of the selected organization.',
                description: 'Owners and managers see anyone\'s; employees their own only (403 otherwise). A rate that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('An employee asked for someone else\'s rate.'),
                    '404' => new Response('The rate is not one of the selected organization\'s.'),
                ],
            ),
            provider: HourlyRateProvider::class,
        ),
        new Post(
            uriTemplate: '/hourly-rates',
            openapi: new Operation(
                summary: 'Gives a member an hourly rate from a business date.',
                description: 'Owners and managers only (403 otherwise), for any member, active or deactivated, themselves included; a userId that is not a member is a 404. The currency is the organization\'s. One rate per member and date (409). The rate is in effect until the member\'s next rate; if any of those dates is in a locked period, it is a 409.',
                responses: [
                    '201' => new Response('The new rate.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The userId is not a member of the selected organization.'),
                    '409' => new Response('The member has a rate from that date, or a date the rate would be in effect on is locked.'),
                    '422' => new Response('A field is missing or invalid.'),
                ],
            ),
            input: SetHourlyRateInput::class,
            read: false,
            processor: SetHourlyRateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/hourly-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Corrects an hourly rate\'s date or amount.',
                description: 'Owners and managers only (403 otherwise); a rate that is not the selected organization\'s is a 404. The currency becomes the organization\'s. Another rate of the member from the new date is a 409. If a date the rate is in effect on before or after the change is in a locked period, it is a 409. Changing nothing is a 200.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The rate is not one of the selected organization\'s.'),
                    '409' => new Response('Another rate of the member is from the new date, or an affected date is locked.'),
                    '422' => new Response('A field is invalid.'),
                ],
            ),
            input: UpdateHourlyRateInput::class,
            provider: UpdateHourlyRateInputProvider::class,
            processor: UpdateHourlyRateProcessor::class,
        ),
        new Delete(
            uriTemplate: '/hourly-rates/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes an hourly rate.',
                description: 'Owners and managers only (403 otherwise); a rate that is not the selected organization\'s is a 404. Its dates fall back to the member\'s previous rate, or to none. If one of them is in a locked period, it is a 409.',
                responses: [
                    '204' => new Response('The rate was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The rate is not one of the selected organization\'s.'),
                    '409' => new Response('A date the rate is in effect on is locked.'),
                ],
            ),
            read: false,
            processor: DeleteHourlyRateProcessor::class,
        ),
    ],
)]
final readonly class HourlyRateResource
{
    public function __construct(
        public string $id,
        /** The member the rate is for. */
        public string $userId,
        /** The first business date the rate is in effect (YYYY-MM-DD). */
        public string $effectiveFrom,
        /** The last business date the rate is in effect, inclusive: the day before the member's next rate; null while none follows (YYYY-MM-DD). */
        public ?string $effectiveTo,
        /** Gross minor units per hour. */
        public int $amount,
        /** ISO 4217: the organization's when the rate was set or last changed. */
        public string $currency,
        public \DateTimeImmutable $createdAt,
        /** When the rate was last changed; null until then. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(HourlyRateView $view): self
    {
        return new self($view->id, $view->userId, $view->effectiveFrom, $view->effectiveTo, $view->amount, $view->currency, $view->createdAt, $view->updatedAt);
    }
}
