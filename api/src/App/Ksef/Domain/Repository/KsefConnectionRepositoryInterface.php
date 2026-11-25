<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Repository;

use App\Ksef\Domain\Model\KsefConnection;

/** The current organization's KSeF connection (TenantFilter); there is at most one. */
interface KsefConnectionRepositoryInterface
{
    public function findCurrent(): ?KsefConnection;

    /**
     * Across organizations, for the scheduled fan-out only (ADR 0025 §4): the organization ids of the
     * connections that may sync at `$now` (KsefConnection::canSync()), by id. Ids only, no tenant data.
     *
     * @return list<string>
     */
    public function findOrganizationIdsToSync(\DateTimeImmutable $now): array;

    /**
     * Across organizations, for operator tasks that queue one job each (`app:ksef:read-invoice-items`):
     * the organization ids of every connection, whatever its status, by id. Ids only, no tenant data.
     *
     * @return list<string>
     */
    public function findOrganizationIds(): array;

    public function add(KsefConnection $connection): void;

    /** Persists changes made to an already managed connection. */
    public function save(KsefConnection $connection): void;
}
