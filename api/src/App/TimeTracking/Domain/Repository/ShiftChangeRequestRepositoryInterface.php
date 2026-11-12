<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Repository;

use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use Symfony\Component\Uid\Uuid;

/** Shift change requests of the current organization only (TenantFilter). */
interface ShiftChangeRequestRepositoryInterface
{
    public function findById(Uuid $id): ?ShiftChangeRequest;

    /**
     * Requests of everyone or of one person, in any status or in one: newest first (by submission,
     * then by id).
     *
     * @return list<ShiftChangeRequest>
     */
    public function findAll(?ShiftChangeRequestStatus $status = null, ?Uuid $userId = null): array;

    public function add(ShiftChangeRequest $request): void;

    /** Persists changes made to an already managed request. */
    public function save(ShiftChangeRequest $request): void;
}
