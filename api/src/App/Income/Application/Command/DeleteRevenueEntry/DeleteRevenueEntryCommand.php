<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeleteRevenueEntry;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager deletes an entry, e.g. one entered for the wrong register or date, to enter it
 * again. A hard delete: no trace is kept (ADR 0022 §8, until #112 brings month close and a history).
 */
final readonly class DeleteRevenueEntryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $entryId,
    ) {
    }
}
