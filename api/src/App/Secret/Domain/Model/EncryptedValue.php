<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

/**
 * A value encrypted by SecretCipherInterface (ADR 0026 §2): the id of the keyring entry that
 * encrypted it, the random nonce and the ciphertext with its authentication tag, both base64.
 * Nothing in it is readable without the key, but it is still never logged or returned.
 */
final readonly class EncryptedValue
{
    public function __construct(
        public string $keyId,
        public string $nonce,
        public string $ciphertext,
    ) {
        if ('' === $keyId || '' === $nonce || '' === $ciphertext) {
            throw new \InvalidArgumentException('An encrypted value needs a key id, a nonce and a ciphertext.');
        }
    }
}
