<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeactivateCashStorage;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deactivates a cash storage: it is no longer offered (ADR 0033 §3). */
final readonly class DeactivateCashStorageCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $storageId,
    ) {
    }
}
