<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ImportKsefInvoices;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Imports one organization's purchase invoices from KSeF (ADR 0023 §4–5, ADR 0025): queued by the
 * scheduled fan-out, "Import now" and `app:ksef:import`, always with deduplicationKey(), so one
 * organization never has two runs queued or running.
 */
final readonly class ImportKsefInvoicesJob implements OrganizationJobInterface
{
    public function __construct(public string $organizationId)
    {
    }

    public static function deduplicationKey(string $organizationId): string
    {
        return 'ksef-import:'.$organizationId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
