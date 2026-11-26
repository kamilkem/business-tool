<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryMatchingRunRepository implements MatchingRunRepositoryInterface
{
    /** @var array<string, MatchingRun> */
    private array $byId = [];

    public function add(MatchingRun $run): void
    {
        $this->byId[$run->getId()->toRfc4122()] = $run;
    }

    public function save(MatchingRun $run): void
    {
        $this->byId[$run->getId()->toRfc4122()] = $run;
    }

    public function findById(Uuid $id): ?MatchingRun
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findLatest(): ?MatchingRun
    {
        $runs = array_values($this->byId);
        usort($runs, static fn (MatchingRun $a, MatchingRun $b): int => [$b->getStartedAt(), $b->getId()->toRfc4122()] <=> [$a->getStartedAt(), $a->getId()->toRfc4122()]);

        return $runs[0] ?? null;
    }
}
