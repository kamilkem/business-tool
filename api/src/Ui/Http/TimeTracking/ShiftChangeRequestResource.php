<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use App\TimeTracking\Domain\Model\ShiftChangeRequestKind;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\TimeTracking\Processor\ApproveShiftChangeRequestProcessor;
use Ui\Http\TimeTracking\Processor\RejectShiftChangeRequestProcessor;
use Ui\Http\TimeTracking\Processor\SubmitShiftChangeRequestProcessor;
use Ui\Http\TimeTracking\Provider\ShiftChangeRequestCollectionProvider;
use Ui\Http\TimeTracking\Provider\ShiftChangeRequestProvider;

/**
 * A member's request to an owner or manager to change their shifts, in the organization selected by
 * the X-Organization-Id header: to enter a shift they worked but did not clock, or to give one of
 * their own shifts new times (ADR 0016 §5: people never change shift times themselves). Any member
 * submits requests for themselves; owners and managers approve or reject them, their own included.
 * Approving applies the change to the shift with the approver as the corrector, under the shift
 * rules as they hold at that moment.
 */
#[ApiResource(
    shortName: 'ShiftChangeRequest',
    operations: [
        new GetCollection(
            uriTemplate: '/shift-change-requests',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists shift change requests in the selected organization.',
                description: 'Newest first. Owners and managers see everyone\'s requests, deactivated members\' too; employees see their own only. An unknown status is a 422.',
                responses: [
                    '422' => new Response('The status is not one of pending, approved, rejected.'),
                ],
            ),
            // ListShiftChangeRequestsInput validates the parameters; the schema below only documents them.
            queryParameterValidationEnabled: false,
            provider: ShiftChangeRequestCollectionProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected']],
                    description: 'Only requests in this status.',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/shift-change-requests/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One shift change request.',
                description: 'Owners and managers see any request; employees their own only. Any other request is a 404.',
                responses: [
                    '404' => new Response('The request is not one the signed-in user may see in the selected organization.'),
                ],
            ),
            provider: ShiftChangeRequestProvider::class,
        ),
        new Post(
            uriTemplate: '/shift-change-requests',
            openapi: new Operation(
                summary: 'Asks an owner or manager to change the signed-in user\'s shifts.',
                description: 'Any member, for themselves only. Without shiftId it asks for a missing shift, whole (endedAt required); with shiftId it asks for new times for one of their own shifts (someone else\'s or an unknown shift is a 404), where a null endedAt keeps an open shift open and an ended shift keeps an end (422). The note is required. The shift must end after it starts and nothing may be in the future (422); times that are the shift\'s own are a 422. Overlaps with other shifts are checked only when the request is approved. Owners and managers (but the requester) are e-mailed.',
                responses: [
                    '201' => new Response('The pending request.'),
                    '404' => new Response('The shift is not one of the signed-in user\'s in the selected organization.'),
                    '422' => new Response('A field is missing or invalid, the shift would not end after it starts, would be in the future, would lose its end, or would not change.'),
                ],
            ),
            input: SubmitShiftChangeRequestInput::class,
            read: false,
            processor: SubmitShiftChangeRequestProcessor::class,
        ),
        new Post(
            uriTemplate: '/shift-change-requests/{id}/approve',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Approves a pending shift change request and applies it to the shift.',
                description: 'Owners and managers only (403 otherwise), their own requests included. The request has no body. The missing shift is entered, or the shift gets the new times (it keeps its own note), with the approver as the corrector (correctedBy), under the shift rules as they hold now: an overlap with another shift of the person is a 409; a time in the future, an end not after the start or a reopened shift is a 422. Then the request stays pending. A request already decided is a 409. The requester is e-mailed.',
                responses: [
                    '200' => new Response('The approved request; shiftId is the shift that was entered or changed.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The request, or the shift it is about, is not in the selected organization.'),
                    '409' => new Response('The request was already decided, or the shift would overlap another shift of the person.'),
                    '422' => new Response('The shift would not end after it starts, would be in the future, or would lose its end.'),
                ],
            ),
            input: false,
            read: false,
            processor: ApproveShiftChangeRequestProcessor::class,
        ),
        new Post(
            uriTemplate: '/shift-change-requests/{id}/reject',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Rejects a pending shift change request.',
                description: 'Owners and managers only (403 otherwise), their own requests included; the reason is optional. The shifts stay as they are. A request already decided is a 409. The requester is e-mailed.',
                responses: [
                    '200' => new Response('The rejected request.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The request is not in the selected organization.'),
                    '409' => new Response('The request was already decided.'),
                    '422' => new Response('The reason is too long.'),
                ],
            ),
            input: RejectShiftChangeRequestInput::class,
            read: false,
            processor: RejectShiftChangeRequestProcessor::class,
        ),
    ],
)]
final readonly class ShiftChangeRequestResource
{
    public function __construct(
        public string $id,
        /** The member who asked; the shift is theirs. */
        public string $userId,
        public string $firstName,
        public string $lastName,
        /** A missing shift to enter, or new times for an existing shift. */
        public ShiftChangeRequestKind $kind,
        /** The shift to give new times to; for a missing shift, the shift entered on approval (null until then). */
        public ?string $shiftId,
        /** The start asked for. */
        public \DateTimeImmutable $startedAt,
        /** The end asked for; null keeps an open shift open. */
        public ?\DateTimeImmutable $endedAt,
        /** Why the change is needed. */
        public string $note,
        public ShiftChangeRequestStatus $status,
        public \DateTimeImmutable $submittedAt,
        /** The owner or manager who approved or rejected it; null while pending. */
        public ?string $decidedBy,
        public ?\DateTimeImmutable $decidedAt,
        /** Why it was rejected, if the owner or manager said. */
        public ?string $rejectionReason,
        /** The shift's start now, to compare with the times asked for; null when there is no shift (yet). */
        public ?\DateTimeImmutable $shiftStartedAt,
        /** The shift's end now; null when the shift is open or there is no shift (yet). */
        public ?\DateTimeImmutable $shiftEndedAt,
    ) {
    }

    public static function fromView(ShiftChangeRequestView $view): self
    {
        return new self(
            id: $view->id,
            userId: $view->userId,
            firstName: $view->firstName,
            lastName: $view->lastName,
            kind: ShiftChangeRequestKind::from($view->kind),
            shiftId: $view->shiftId,
            startedAt: $view->startedAt,
            endedAt: $view->endedAt,
            note: $view->note,
            status: ShiftChangeRequestStatus::from($view->status),
            submittedAt: $view->submittedAt,
            decidedBy: $view->decidedBy,
            decidedAt: $view->decidedAt,
            rejectionReason: $view->rejectionReason,
            shiftStartedAt: $view->shiftStartedAt,
            shiftEndedAt: $view->shiftEndedAt,
        );
    }
}
