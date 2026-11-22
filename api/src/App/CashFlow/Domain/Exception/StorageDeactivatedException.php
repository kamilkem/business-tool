<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * A deactivated storage is no longer offered (ADR 0033 §3): cash is only taken out of it, with an
 * adjustment that only subtracts or a transfer out of it, so it can be emptied.
 */
final class StorageDeactivatedException extends CashFlowException
{
    public static function of(string $storageId, string $name): self
    {
        return new self(\sprintf('The cash storage "%s" is deactivated: cash can only be taken out of it.', $name), ['storageId' => $storageId, 'name' => $name]);
    }
}
