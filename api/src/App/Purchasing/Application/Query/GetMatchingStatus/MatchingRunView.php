<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetMatchingStatus;

use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Model\MatchingRunOutcome;

final readonly class MatchingRunView
{
    /** A run that has not ended is `running` for this long, then `interrupted` (the worker died). */
    public const int RUNNING_FOR_AT_MOST_SECONDS = 3600;

    public function __construct(
        public string $id,
        public MatchingRunState $state,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        /** Items the model went through. */
        public int $interpreted,
        public int $assigned,
        public int $suggested,
        /** Items the model went through and found no product for. */
        public int $foundNothing,
    ) {
    }

    public static function fromRun(MatchingRun $run, \DateTimeImmutable $now): self
    {
        $counts = $run->getCounts();
        $state = match ($run->getOutcome()) {
            MatchingRunOutcome::Done => MatchingRunState::Done,
            MatchingRunOutcome::ModelUnavailable => MatchingRunState::ModelUnavailable,
            null => $now->getTimestamp() - $run->getStartedAt()->getTimestamp() < self::RUNNING_FOR_AT_MOST_SECONDS ? MatchingRunState::Running : MatchingRunState::Interrupted,
        };

        return new self($run->getId()->toRfc4122(), $state, $run->getStartedAt(), $run->getFinishedAt(), $counts->interpreted, $counts->assigned, $counts->suggested, $counts->foundNothing());
    }
}
