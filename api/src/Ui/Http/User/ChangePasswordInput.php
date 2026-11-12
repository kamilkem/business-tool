<?php

declare(strict_types=1);

namespace Ui\Http\User;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Ui\Http\User\Validator\PasswordRequirements;

final readonly class ChangePasswordInput
{
    public function __construct(
        /** The signed-in user's password now; a wrong one is a violation on this field. */
        #[UserPassword(message: 'The current password is wrong.')]
        #[\SensitiveParameter]
        public string $currentPassword,
        /** The new password: at least 8 characters, at most 4096 bytes; no composition rules (ADR 0013). */
        #[PasswordRequirements]
        #[\SensitiveParameter]
        public string $newPassword,
    ) {
    }
}
