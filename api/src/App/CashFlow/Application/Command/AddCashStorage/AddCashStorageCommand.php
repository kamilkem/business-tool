<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\AddCashStorage;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds a cash storage (ADR 0033 §3); the mode is `counted` or `deposit`. */
final readonly class AddCashStorageCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $storageId,
        public string $name,
        public string $mode,
    ) {
    }
}
