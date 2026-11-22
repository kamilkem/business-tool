<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** Cash storage names are unique within an organization, deactivated storages included (ADR 0033 §3). */
final class StorageNameTakenException extends CashFlowException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The organization already has a cash storage named "%s".', $name), ['name' => $name]);
    }
}
