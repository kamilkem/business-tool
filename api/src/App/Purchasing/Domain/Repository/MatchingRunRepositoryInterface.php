<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Model\MatchingRun;
use Symfony\Component\Uid\Uuid;

/** Matching runs of the current organization only (TenantFilter). */
interface MatchingRunRepositoryInterface
{
    public function add(MatchingRun $run): void;

    /** Persists changes made to a managed run. */
    public function save(MatchingRun $run): void;

    public function findById(Uuid $id): ?MatchingRun;

    /** The run that started last, finished or not. */
    public function findLatest(): ?MatchingRun;
}
