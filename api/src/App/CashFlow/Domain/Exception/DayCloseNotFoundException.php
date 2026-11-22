<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** No such end-of-day close in this organization (another organization's is not found either). */
final class DayCloseNotFoundException extends CashFlowException
{
    public static function withId(string $dayCloseId): self
    {
        return new self(\sprintf('Day close "%s" not found.', $dayCloseId), ['dayCloseId' => $dayCloseId]);
    }
}
