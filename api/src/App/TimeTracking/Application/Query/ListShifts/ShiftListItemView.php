<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListShifts;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\Shared\Domain\Time\BusinessDate;
use App\TimeTracking\Domain\Model\Shift;

final readonly class ShiftListItemView
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $firstName,
        public string $lastName,
        /** The business date the shift belongs to: the local date it started on (YYYY-MM-DD). */
        public string $date,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        /** Whole minutes worked; null while the shift is open. */
        public ?int $durationMinutes,
        public ?string $note,
        /** How the shift came to be: ShiftSource, `clock` or `manager`. */
        public string $source,
        /** The owner or manager who entered the shift or last changed it; null while it is as clocked. */
        public ?string $correctedBy,
        public ?\DateTimeImmutable $correctedAt,
    ) {
    }

    public static function of(Shift $shift, MemberView $member, \DateTimeZone $timeZone): self
    {
        $endedAt = $shift->getEndedAt();

        return new self(
            $shift->getId()->toRfc4122(),
            $shift->getUserId()->toRfc4122(),
            $member->firstName,
            $member->lastName,
            BusinessDate::containing($shift->getStartedAt(), $timeZone)->toString(),
            $shift->getStartedAt(),
            $endedAt,
            null === $endedAt ? null : intdiv($endedAt->getTimestamp() - $shift->getStartedAt()->getTimestamp(), 60),
            $shift->getNote(),
            $shift->getSource()->value,
            $shift->getCorrectedBy()?->toRfc4122(),
            $shift->getCorrectedAt(),
        );
    }
}
