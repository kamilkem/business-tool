<?php

declare(strict_types=1);

namespace App\Shared\Application\Job;

/**
 * Work queued for the jobs worker (ADR 0025 §2): a `final readonly` message with primitive fields in
 * `App\<Module>\Application\Job\<UseCase>\`, handled later by a JobHandlerInterface on the
 * `jobs-worker`. A job is idempotent and safe to interrupt at any point: each unit of work is its own
 * flush, and progress is recorded after it.
 *
 * A job without an organization (a fan-out) sees no tenant data; it asks the query bus for ids and
 * queues one OrganizationJobInterface job each.
 */
interface JobInterface
{
}
