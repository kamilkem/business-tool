<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** No such register in this organization (another organization's is not found either). */
final class RegisterNotFoundException extends IncomeException
{
    public static function withId(string $registerId): self
    {
        return new self(\sprintf('Register "%s" not found.', $registerId), ['registerId' => $registerId]);
    }
}
