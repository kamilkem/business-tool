<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\ReEncryptOrganizationSecret;

use App\Shared\Application\Command\CommandInterface;

/**
 * During a key rotation, the secret's value is decrypted and encrypted again with the current key
 * (ADR 0026 §4). A secret already under the current key is left as it is, so running it again after a
 * failure is safe. Sent by the rotation, not by a user.
 */
final readonly class ReEncryptOrganizationSecretCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $secretId,
    ) {
    }
}
