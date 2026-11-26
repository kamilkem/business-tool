<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetMatchingStatus\MatchingRunState;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingRunView;

/** A matching run that used the model (ADR 0046 §6). */
final readonly class MatchingStatusRun
{
    public function __construct(
        public string $id,
        /** `running` (not ended, for at most an hour), `interrupted` (never ended), `done`, or `model_unavailable` (it stopped because the model could not answer). */
        public MatchingRunState $state,
        public \DateTimeImmutable $startedAt,
        /** Null while it has not ended. */
        public ?\DateTimeImmutable $finishedAt,
        /** Items the model went through. */
        public int $interpreted,
        /** Of those, items it linked with confidence. */
        public int $assigned,
        /** Of those, items it linked for a person to confirm. */
        public int $suggested,
        /** Of those, items it found no product for. */
        public int $foundNothing,
    ) {
    }

    public static function fromView(MatchingRunView $view): self
    {
        return new self($view->id, $view->state, $view->startedAt, $view->finishedAt, $view->interpreted, $view->assigned, $view->suggested, $view->foundNothing);
    }
}
