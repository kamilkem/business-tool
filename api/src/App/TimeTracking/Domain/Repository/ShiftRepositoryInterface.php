<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Repository;

use App\Shared\Domain\Time\UtcRange;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use Symfony\Component\Uid\Uuid;

/** Shifts of the current organization only (TenantFilter). */
interface ShiftRepositoryInterface
{
    public function findById(Uuid $id): ?Shift;

    /** The person's open shift (clocked in, not yet out), if any. */
    public function findOpenByUser(Uuid $userId): ?Shift;

    /**
     * Shifts that started in the range (`>= from AND < to`), open ones included, of one person or
     * of everyone: newest first (by start, then by id).
     *
     * @return list<Shift>
     */
    public function findStartedIn(UtcRange $range, ?Uuid $userId = null): array;

    /**
     * The person's shifts that share a moment with [from, to), or with everything from `from` on when
     * `to` is null; open shifts count as running on. For Shift::record() and Shift::correct(), which
     * check the overlap themselves.
     *
     * @return list<Shift>
     */
    public function findOverlapping(Uuid $userId, \DateTimeImmutable $from, ?\DateTimeImmutable $to): array;

    /**
     * @throws ShiftAlreadyOpenException when another open shift of the person was stored in the
     *                                   meantime (the partial unique index caught a concurrent clock-in)
     */
    public function add(Shift $shift): void;

    /** Persists changes made to an already managed shift. */
    public function save(Shift $shift): void;
}
