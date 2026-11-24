<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\ChangeKsefSyncStartDate;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager chooses the date of issue from which KSeF purchase invoices are imported, or
 * null for the default (ADR 0038).
 */
final readonly class ChangeKsefSyncStartDateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** `Y-m-d`, or null for the default. */
        public ?string $syncStartDate,
    ) {
    }
}
