<?php

declare(strict_types=1);

namespace Ui\Http\User\Validator;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rules for a new password (ADR 0013), shared by every Input that sets one (set-password here,
 * changing one's own password in #12): at least 8 characters, at most 4096 bytes (4096 characters
 * of plain ASCII), no composition rules. The maximum is in bytes because that is what the password
 * hasher accepts (PasswordHasherInterface::MAX_PASSWORD_LENGTH); a longer password would be a 500.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class PasswordRequirements extends Assert\Compound
{
    public const int MIN_LENGTH = 8;
    public const int MAX_BYTES = PasswordHasherInterface::MAX_PASSWORD_LENGTH;

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Length(min: self::MIN_LENGTH),
            new Assert\Length(max: self::MAX_BYTES, countUnit: Assert\Length::COUNT_BYTES),
        ];
    }
}
