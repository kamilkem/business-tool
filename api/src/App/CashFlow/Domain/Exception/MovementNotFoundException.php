<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** No such cash movement in this organization (another organization's is not found either). */
final class MovementNotFoundException extends CashFlowException
{
    public static function withId(string $movementId): self
    {
        return new self(\sprintf('Cash movement "%s" not found.', $movementId), ['movementId' => $movementId]);
    }
}
