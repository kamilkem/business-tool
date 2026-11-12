<?php

declare(strict_types=1);

namespace App\User\Application\Command\ChangePassword;

use App\Shared\Application\Command\CommandInterface;

/**
 * A signed-in user replaces their own password (#12). The caller has already checked the current
 * password (ChangePasswordInput) and the rules for a new one (ADR 0013). It ends every session of the
 * user (ADR 0020); a caller that keeps the person signed in on this device starts a new one afterwards.
 */
final readonly class ChangePasswordCommand implements CommandInterface
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $newPassword,
    ) {
    }
}
