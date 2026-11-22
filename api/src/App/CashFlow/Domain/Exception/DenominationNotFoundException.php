<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** No such denomination in this organization (another organization's is not found either). */
final class DenominationNotFoundException extends CashFlowException
{
    public static function withId(string $denominationId): self
    {
        return new self(\sprintf('Denomination "%s" not found.', $denominationId), ['denominationId' => $denominationId]);
    }
}
