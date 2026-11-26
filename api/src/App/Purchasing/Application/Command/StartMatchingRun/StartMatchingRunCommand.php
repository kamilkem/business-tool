<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\StartMatchingRun;

use App\Shared\Application\Command\CommandInterface;

/** The matching job records that a run that uses the model started (ADR 0046 §6). It has no actor and is never sent from Ui. */
final readonly class StartMatchingRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $runId,
        /** A MatchScope value. */
        public string $scope,
    ) {
    }
}
