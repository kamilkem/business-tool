<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShiftChangeRequest;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;

final readonly class ShiftChangeRequestView
{
    public function __construct(
        public string $id,
        /** The member who asked. */
        public string $userId,
        public string $firstName,
        public string $lastName,
        /** ShiftChangeRequestKind: `missing_shift` or `new_times`. */
        public string $kind,
        /** The shift to give new times to, or the shift entered when a missing-shift request was approved. */
        public ?string $shiftId,
        /** The times asked for. */
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public string $note,
        /** ShiftChangeRequestStatus: `pending`, `approved` or `rejected`. */
        public string $status,
        public \DateTimeImmutable $submittedAt,
        public ?string $decidedBy,
        public ?\DateTimeImmutable $decidedAt,
        public ?string $rejectionReason,
        /** The shift's times now (null when there is no shift yet, or it no longer exists). */
        public ?\DateTimeImmutable $shiftStartedAt,
        public ?\DateTimeImmutable $shiftEndedAt,
    ) {
    }

    /** @param ?Shift $shift the request's shift (ShiftChangeRequest::getShiftId()), if it exists */
    public static function of(ShiftChangeRequest $request, MemberView $requester, ?Shift $shift): self
    {
        return new self(
            $request->getId()->toRfc4122(),
            $request->getUserId()->toRfc4122(),
            $requester->firstName,
            $requester->lastName,
            $request->getKind()->value,
            $request->getShiftId()?->toRfc4122(),
            $request->getStartedAt(),
            $request->getEndedAt(),
            $request->getNote(),
            $request->getStatus()->value,
            $request->getSubmittedAt(),
            $request->getDecidedBy()?->toRfc4122(),
            $request->getDecidedAt(),
            $request->getRejectionReason(),
            $shift?->getStartedAt(),
            $shift?->getEndedAt(),
        );
    }
}
