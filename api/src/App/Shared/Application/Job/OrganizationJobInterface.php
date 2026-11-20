<?php

declare(strict_types=1);

namespace App\Shared\Application\Job;

/**
 * A job that works on one organization's data. The job bus switches the tenant to it before the
 * handler runs and resets it afterwards, also on failure (ADR 0025 §4); an organization that no longer
 * exists makes the job unrecoverable.
 */
interface OrganizationJobInterface extends JobInterface
{
    public function organizationId(): string;
}
