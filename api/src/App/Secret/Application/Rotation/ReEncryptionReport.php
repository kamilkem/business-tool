<?php

declare(strict_types=1);

namespace App\Secret\Application\Rotation;

/** What a rotation run did. The old key can leave the keyring once a run re-encrypts nothing and finds nothing unreadable. */
final readonly class ReEncryptionReport
{
    /** @param list<string> $unreadableSecretIds secrets that did not decrypt with any key of the keyring */
    public function __construct(
        public int $organizations,
        public int $reEncrypted,
        public array $unreadableSecretIds,
    ) {
    }
}
