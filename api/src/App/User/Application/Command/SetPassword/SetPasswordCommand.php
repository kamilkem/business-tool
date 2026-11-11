<?php

declare(strict_types=1);

namespace App\User\Application\Command\SetPassword;

use App\Shared\Application\Command\CommandInterface;

/**
 * Sets a password with the secret from an e-mailed link (an invitation or a reset; ADR 0013). It
 * does not sign the person in.
 */
final readonly class SetPasswordCommand implements CommandInterface
{
    public function __construct(
        /** The secret from the link, as the page read it from the URL fragment. */
        public string $token,
        #[\SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}
