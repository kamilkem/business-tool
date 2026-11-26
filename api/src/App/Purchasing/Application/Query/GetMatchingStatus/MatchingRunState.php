<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetMatchingStatus;

/** Where a matching run stands (ADR 0046 §6). */
enum MatchingRunState: string
{
    /** Started and not ended, for at most an hour: a job is going through the items or a continuation is queued. */
    case Running = 'running';
    /** Never ended and older than an hour: the worker died. */
    case Interrupted = 'interrupted';
    case Done = 'done';
    /** Ended because the model could not answer; the items it did not reach stay for the next run. */
    case ModelUnavailable = 'model_unavailable';
}
