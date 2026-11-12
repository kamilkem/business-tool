<?php

declare(strict_types=1);

namespace App\User\Application\Command\RevokeSession;

use App\Shared\Application\Command\CommandInterface;

/** Sign-out (ADR 0020): ends the session the presented refresh token belongs to. */
final readonly class RevokeSessionCommand implements CommandInterface
{
    public function __construct(
        #[\SensitiveParameter]
        public string $presentedSecret,
    ) {
    }
}
