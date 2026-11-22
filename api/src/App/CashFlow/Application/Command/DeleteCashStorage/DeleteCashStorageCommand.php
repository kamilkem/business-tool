<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteCashStorage;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a cash storage that no close or movement refers to (ADR 0033 §3). */
final readonly class DeleteCashStorageCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $storageId,
    ) {
    }
}
