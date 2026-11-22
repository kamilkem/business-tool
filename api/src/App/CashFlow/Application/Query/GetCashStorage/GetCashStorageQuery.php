<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashStorage;

use App\Shared\Application\Query\QueryInterface;

/**
 * One cash storage of the current organization (TenantFilter), active or deactivated, for any
 * active member (ADR 0033 §6). It carries no balance. Returns null when it does not exist there or
 * the actor is not an active member. With `toManage`, an employee is refused (403), for a write that
 * starts from the storage's current state (a merge-patch).
 */
final readonly class GetCashStorageQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $storageId,
        public bool $toManage = false,
    ) {
    }
}
