<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\FinishMatchingRun;

use App\Shared\Application\Command\CommandInterface;

/**
 * The matching job ends a run it recorded (ADR 0046 §6): the handler counts what the model did to the items since the
 * run started. No actor; never sent from Ui.
 */
final readonly class FinishMatchingRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $runId,
        /** A MatchingRunOutcome value. */
        public string $outcome,
    ) {
    }
}
