<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Register names are unique within an organization, deactivated registers included (ADR 0022 §2). */
final class RegisterNameTakenException extends IncomeException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The organization already has a register named "%s".', $name));
    }
}
