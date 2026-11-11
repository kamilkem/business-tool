<?php

declare(strict_types=1);

namespace Ui\Http\User;

use Symfony\Component\Validator\Constraints as Assert;
use Ui\Http\User\Validator\PasswordRequirements;

final readonly class SetPasswordInput
{
    public function __construct(
        /** The secret from the e-mailed link (the `token` in its URL fragment). */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $token,
        /** The new password: at least 8 characters, at most 4096 bytes; no composition rules. */
        #[PasswordRequirements]
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
