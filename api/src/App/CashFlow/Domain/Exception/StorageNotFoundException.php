<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** No such cash storage in this organization (another organization's is not found either). */
final class StorageNotFoundException extends CashFlowException
{
    public static function withId(string $storageId): self
    {
        return new self(\sprintf('Cash storage "%s" not found.', $storageId), ['storageId' => $storageId]);
    }
}
