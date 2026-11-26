<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetMatchingStatus;

final readonly class MatchingStatusView
{
    public function __construct(
        /** The matchable items with no product: what "Match again" goes through. */
        public int $waitingCount,
        /** An interpreter is configured (`ANTHROPIC_API_KEY`); without one only the owner's aliases match items. */
        public bool $modelEnabled,
        public ?MatchingRunView $lastRun,
    ) {
    }
}
