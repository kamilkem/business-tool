<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShift;

use App\TimeTracking\Domain\Model\Shift;

final readonly class ShiftView
{
    public function __construct(
        public string $id,
        public string $userId,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public ?string $note,
    ) {
    }

    public static function fromShift(Shift $shift): self
    {
        return new self(
            $shift->getId()->toRfc4122(),
            $shift->getUserId()->toRfc4122(),
            $shift->getStartedAt(),
            $shift->getEndedAt(),
            $shift->getNote(),
        );
    }
}
