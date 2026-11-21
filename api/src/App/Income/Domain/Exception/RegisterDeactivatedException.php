<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** A deactivated register takes no new entries; its existing entries stay editable (ADR 0022 §2). */
final class RegisterDeactivatedException extends IncomeException
{
    public static function of(string $registerId): self
    {
        return new self(\sprintf('Register "%s" is deactivated and takes no new entries.', $registerId), ['registerId' => $registerId]);
    }
}
