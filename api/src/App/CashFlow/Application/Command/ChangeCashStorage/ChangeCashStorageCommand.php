<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ChangeCashStorage;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager renames a cash storage or changes its mode (`counted` or `deposit`), or both
 * (ADR 0033 §3). A mode change applies from the next close.
 */
final readonly class ChangeCashStorageCommand implements CommandInterface
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
