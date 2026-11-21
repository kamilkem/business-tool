<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryShiftChangeRequestRepository implements ShiftChangeRequestRepositoryInterface
{
    /** @var array<string, ShiftChangeRequest> */
    private array $byId = [];

    public int $saveCallCount = 0;

    public function findById(Uuid $id): ?ShiftChangeRequest
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findAll(?ShiftChangeRequestStatus $status = null, ?Uuid $userId = null): array
    {
        $requests = array_values(array_filter(
            $this->byId,
            static fn (ShiftChangeRequest $request): bool => (null === $status || $request->getStatus() === $status)
                && (null === $userId || $request->getUserId()->equals($userId)),
        ));
        usort($requests, static fn (ShiftChangeRequest $a, ShiftChangeRequest $b): int => [$b->getSubmittedAt(), $b->getId()->toRfc4122()] <=> [$a->getSubmittedAt(), $a->getId()->toRfc4122()]);

        return $requests;
    }

    public function findPendingAboutShift(Uuid $shiftId): array
    {
        $requests = array_values(array_filter(
            $this->byId,
            static fn (ShiftChangeRequest $request): bool => ShiftChangeRequestStatus::Pending === $request->getStatus()
                && null !== $request->getShiftId() && $request->getShiftId()->equals($shiftId),
        ));
        usort($requests, static fn (ShiftChangeRequest $a, ShiftChangeRequest $b): int => [$a->getSubmittedAt(), $a->getId()->toRfc4122()] <=> [$b->getSubmittedAt(), $b->getId()->toRfc4122()]);

        return $requests;
    }

    public function add(ShiftChangeRequest $request): void
    {
        $this->byId[$request->getId()->toRfc4122()] = $request;
    }

    public function save(ShiftChangeRequest $request): void
    {
        ++$this->saveCallCount;
        $this->byId[$request->getId()->toRfc4122()] = $request;
    }

    /** @return list<ShiftChangeRequest> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
