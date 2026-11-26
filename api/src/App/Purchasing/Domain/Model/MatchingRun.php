<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Matching\ModelOutcomeCounts;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One matching run that used the model, from its first job to its last (ADR 0046 §6): when it started and
 * ended, how it ended and what the model did to the items. A run that continues in a new job after its time
 * budget stays one run. Written by the matching job only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'matching_run')]
#[ORM\Index(name: 'matching_run_started_idx', columns: ['organization_id', 'started_at'])]
class MatchingRun implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 24, enumType: MatchScope::class)]
    private MatchScope $scope;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 24, nullable: true, enumType: MatchingRunOutcome::class)]
    private ?MatchingRunOutcome $outcome = null;

    /** Items the model went through, whatever came out. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $interpreted = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $assigned = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $suggested = 0;

    public function __construct(Uuid $id, MatchScope $scope, \DateTimeImmutable $startedAt)
    {
        $this->id = $id;
        $this->scope = $scope;
        $this->startedAt = $startedAt;
    }

    /** Ends the run with what the model did since it started; ending it again changes nothing. */
    public function finish(MatchingRunOutcome $outcome, ModelOutcomeCounts $counts, \DateTimeImmutable $now): void
    {
        if (null !== $this->finishedAt) {
            return;
        }

        $this->finishedAt = $now;
        $this->outcome = $outcome;
        $this->interpreted = $counts->interpreted;
        $this->assigned = $counts->assigned;
        $this->suggested = $counts->suggested;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): MatchScope
    {
        return $this->scope;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getOutcome(): ?MatchingRunOutcome
    {
        return $this->outcome;
    }

    public function getCounts(): ModelOutcomeCounts
    {
        return new ModelOutcomeCounts($this->interpreted, $this->assigned, $this->suggested);
    }
}
