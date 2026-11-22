<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** Only a cash storage no close or movement refers to can be deleted; a used one is deactivated (ADR 0033 §3). */
final class StorageInUseException extends CashFlowException
{
    public static function of(string $storageId, string $name): self
    {
        return new self(\sprintf('The cash storage "%s" is used by cash records; deactivate it instead.', $name), ['storageId' => $storageId, 'name' => $name]);
    }
}
