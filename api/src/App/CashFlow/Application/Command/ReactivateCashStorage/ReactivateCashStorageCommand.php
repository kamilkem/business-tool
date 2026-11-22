<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ReactivateCashStorage;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager reactivates a cash storage: it is offered again (ADR 0033 §3). */
final readonly class ReactivateCashStorageCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $storageId,
    ) {
    }
}
