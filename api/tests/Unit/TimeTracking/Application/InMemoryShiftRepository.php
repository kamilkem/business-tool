<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Shared\Domain\Time\UtcRange;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryShiftRepository implements ShiftRepositoryInterface
{
    /** @var array<string, Shift> */
    private array $byId = [];

    public int $saveCallCount = 0;

    /**
     * Makes findOpenByUser() miss open shifts, as a request does when a concurrent clock-in has not
     * committed yet; add() still refuses a second open shift, like the partial unique index.
     */
    public bool $staleReads = false;

    public function findById(Uuid $id): ?Shift
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findOpenByUser(Uuid $userId): ?Shift
    {
        return $this->staleReads ? null : $this->openOf($userId);
    }

    public function findStartedIn(UtcRange $range, ?Uuid $userId = null): array
    {
        $shifts = array_values(array_filter(
            $this->byId,
            static fn (Shift $shift): bool => $range->contains($shift->getStartedAt())
                && (null === $userId || $shift->getUserId()->equals($userId)),
        ));
        usort($shifts, static fn (Shift $a, Shift $b): int => [$b->getStartedAt(), $b->getId()->toRfc4122()] <=> [$a->getStartedAt(), $a->getId()->toRfc4122()]);

        return $shifts;
    }

    public function findOverlapping(Uuid $userId, \DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (Shift $shift): bool => $shift->getUserId()->equals($userId)
                && (null === $shift->getEndedAt() || $shift->getEndedAt() > $from)
                && (null === $to || $shift->getStartedAt() < $to),
        ));
    }

    public function add(Shift $shift): void
    {
        // Like the partial unique index: only a second open shift is refused.
        if ($shift->isOpen() && null !== $this->openOf($shift->getUserId())) {
            throw ShiftAlreadyOpenException::forUser($shift->getUserId()->toRfc4122());
        }

        $this->byId[$shift->getId()->toRfc4122()] = $shift;
    }

    public function save(Shift $shift): void
    {
        ++$this->saveCallCount;
        $this->byId[$shift->getId()->toRfc4122()] = $shift;
    }

    /** @return list<Shift> */
    public function all(): array
    {
        return array_values($this->byId);
    }

    private function openOf(Uuid $userId): ?Shift
    {
        foreach ($this->byId as $shift) {
            if ($shift->isOpen() && $shift->getUserId()->equals($userId)) {
                return $shift;
            }
        }

        return null;
    }
}
