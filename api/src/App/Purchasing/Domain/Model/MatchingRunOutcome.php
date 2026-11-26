<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

/** How a matching run that used the model ended (ADR 0046 §6). A run that has not ended has none. */
enum MatchingRunOutcome: string
{
    /** Every item of its scope went through. */
    case Done = 'done';
    /** The model could not answer (down, rate limited, rejected credentials); the items it did not reach stay for the next run. */
    case ModelUnavailable = 'model_unavailable';
}
