<?php

declare(strict_types=1);

namespace App\Shared\Application\Job;

/**
 * Handles one kind of job on the jobs worker (ADR 0025 §2). It orchestrates: asks the query bus, calls
 * ports and sends ordinary commands on the command bus, one aggregate each. Expected outcomes (a
 * rate limit, rejected credentials, an organization that no longer qualifies) are recorded and the
 * handler returns normally; a failure a retry cannot fix throws
 * Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException.
 */
interface JobHandlerInterface
{
}
