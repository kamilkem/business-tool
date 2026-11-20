<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Repository;

use App\Ksef\Domain\Model\KsefConnection;

/** The current organization's KSeF connection (TenantFilter); there is at most one. */
interface KsefConnectionRepositoryInterface
{
    public function findCurrent(): ?KsefConnection;

    public function add(KsefConnection $connection): void;

    /** Persists changes made to an already managed connection. */
    public function save(KsefConnection $connection): void;
}
