<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use Ui\Http\TimeTracking\Processor\ClockInProcessor;
use Ui\Http\TimeTracking\Processor\ClockOutProcessor;
use Ui\Http\TimeTracking\Provider\CurrentShiftProvider;

/**
 * The signed-in user's working time in the organization selected by the X-Organization-Id header.
 * Every member clocks in and out for themselves only, on their own phone (ADR 0016); the server's
 * clock sets the times, so the requests carry no body. There is no location check.
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
    ],
)]
final readonly class ShiftResource
{
    public function __construct(
        public string $id,
        /** The person who worked the shift. */
        public string $userId,
        /** When the person clocked in (server time). */
        public \DateTimeImmutable $startedAt,
        /** When the person clocked out (server time); null while they are clocked in. */
        public ?\DateTimeImmutable $endedAt,
        public ?string $note,
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
        );
    }
}
