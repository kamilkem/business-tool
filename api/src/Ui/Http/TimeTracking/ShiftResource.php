<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Model\ShiftSource;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\TimeTracking\Processor\AddShiftProcessor;
use Ui\Http\TimeTracking\Processor\ClockInProcessor;
use Ui\Http\TimeTracking\Processor\ClockOutProcessor;
use Ui\Http\TimeTracking\Processor\CorrectShiftProcessor;
use Ui\Http\TimeTracking\Processor\DeleteShiftProcessor;
use Ui\Http\TimeTracking\Provider\CorrectShiftInputProvider;
use Ui\Http\TimeTracking\Provider\CurrentShiftProvider;

/**
 * A person's working time in the organization selected by the X-Organization-Id header. Every member
 * clocks in and out for themselves only, on their own phone (ADR 0016); the server's clock sets the
 * times, so those requests carry no body. There is no location check. Owners and managers enter
 * missing shifts and correct shifts' times and notes for anyone (ADR 0016 §5); the shift keeps who
 * did it last and when, and delete shifts for good.
 */
#[ApiResource(
    shortName: 'Shift',
    operations: [
        new Get(
            uriTemplate: '/shifts/current',
            openapi: new Operation(
                summary: "The signed-in user's open shift in the selected organization.",
                description: 'The shift the person clocked in to and has not clocked out of yet. A person who is not clocked in gets a 404.',
                responses: [
                    '404' => new Response('The person is not clocked in.'),
                ],
            ),
            provider: CurrentShiftProvider::class,
        ),
        new Post(
            uriTemplate: '/shifts/clock-in',
            openapi: new Operation(
                summary: 'Clocks the signed-in user in to the selected organization.',
                description: "Opens a shift that starts at the server's current time; the request has no body. Any member may clock in, whatever their role. A person who is already clocked in gets a 409.",
                responses: [
                    '201' => new Response('The new open shift.'),
                    '409' => new Response('The person is already clocked in.'),
                ],
            ),
            input: false,
            read: false,
            processor: ClockInProcessor::class,
        ),
        new Post(
            uriTemplate: '/shifts/clock-out',
            status: 200,
            openapi: new Operation(
                summary: 'Clocks the signed-in user out of the selected organization.',
                description: "Ends the person's open shift at the server's current time; the request has no body. A person who is not clocked in gets a 409; clocking out in the same second as clocking in is a 422.",
                responses: [
                    '200' => new Response('The ended shift.'),
                    '409' => new Response('The person is not clocked in.'),
                    '422' => new Response('The shift would not end after it started.'),
                ],
            ),
            input: false,
            read: false,
            processor: ClockOutProcessor::class,
        ),
        new Post(
            uriTemplate: '/shifts',
            openapi: new Operation(
                summary: 'Enters a shift a member worked but did not clock.',
                description: 'Owners and managers only (403 otherwise), for any member, a deactivated one or themselves included; a person who is not a member is a 404. The shift is entered whole: it must end after it starts (422), not after now (422), and not overlap another shift of the person, an open one counting as running on (409). Times are stored in UTC to the second. The shift records who entered it (source "manager", correctedBy, correctedAt).',
                responses: [
                    '201' => new Response('The entered shift.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The shift overlaps another shift of the person.'),
                    '422' => new Response('A field is missing or invalid, the shift does not end after it starts, or it is in the future.'),
                ],
            ),
            input: AddShiftInput::class,
            read: false,
            processor: AddShiftProcessor::class,
        ),
        new Patch(
            uriTemplate: '/shifts/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: "Corrects a shift's times and note.",
                description: 'Owners and managers only (403 otherwise), for anyone\'s shift, their own included; a shift that is not the selected organization\'s is a 404. Setting endedAt on an open shift ends it (a forgotten clock-out); an open shift may also stay open with a corrected start. An ended shift keeps an end: null is a 422. The shift must end after it starts (422), not after now (422), and not overlap another shift of the person (409). A change records who made it and when (correctedBy, correctedAt); sending the values the shift already has changes nothing.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The shift is not one of the selected organization\'s.'),
                    '409' => new Response('The shift would overlap another shift of the person.'),
                    '422' => new Response('A field is invalid, the shift would not end after it starts, would be in the future, or would lose its end.'),
                ],
            ),
            input: CorrectShiftInput::class,
            provider: CorrectShiftInputProvider::class,
            processor: CorrectShiftProcessor::class,
        ),
        new Delete(
            uriTemplate: '/shifts/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a shift.',
                description: 'Owners and managers only (403 otherwise), for anyone\'s shift, their own included; a shift that is not the selected organization\'s is a 404. The shift is removed and no trace is kept. Refused with a 409 while the shift is still open (the person clocks out, or it is given an end, first), when it starts in a period locked by an approved payroll run, or while a change request about it is pending (decide it first).',
                responses: [
                    '204' => new Response('The shift was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The shift is not one of the selected organization\'s.'),
                    '409' => new Response('The shift is still open, is in a locked period, or has a pending change request.'),
                ],
            ),
            read: false,
            processor: DeleteShiftProcessor::class,
        ),
    ],
)]
final readonly class ShiftResource
{
    public function __construct(
        public string $id,
        /** The person who worked the shift. */
        public string $userId,
        /** When the person clocked in (server time), or as an owner or manager entered or corrected it. */
        public \DateTimeImmutable $startedAt,
        /** When the person clocked out (server time), or as an owner or manager entered or corrected it; null while the shift is open. */
        public ?\DateTimeImmutable $endedAt,
        public ?string $note,
        /** How the shift came to be: the person clocked in, or an owner or manager entered it. */
        public ShiftSource $source,
        /** The owner or manager who entered the shift or last changed it; null while it is as clocked. */
        public ?string $correctedBy,
        /** When the shift was entered or last changed by an owner or manager; null while it is as clocked. */
        public ?\DateTimeImmutable $correctedAt,
    ) {
    }

    public static function fromView(ShiftView $view): self
    {
        return new self(
            id: $view->id,
            userId: $view->userId,
            startedAt: $view->startedAt,
            endedAt: $view->endedAt,
            note: $view->note,
            source: ShiftSource::from($view->source),
            correctedBy: $view->correctedBy,
            correctedAt: $view->correctedAt,
        );
    }
}
