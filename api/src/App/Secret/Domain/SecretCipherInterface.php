<?php

declare(strict_types=1);

namespace App\Secret\Domain;

use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\EncryptedValue;

/**
 * Authenticated encryption of per-organization secrets under the application keyring (ADR 0026 §2–3).
 * The first key of the keyring encrypts; every key decrypts, chosen by the value's key id. A
 * malformed keyring fails at the first use with an exception, never with a fallback.
 */
interface SecretCipherInterface
{
    /** Encrypts with the current key and a fresh random nonce. */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): EncryptedValue;

    /**
     * @throws SecretUnreadableException when the key id is not in the keyring, the value is malformed,
     *                                   or the value or its associated data was changed
     */
    public function decrypt(EncryptedValue $value, string $associatedData): string;

    /** The id of the key that encrypts: values under any other key are re-encrypted in a rotation. */
    public function currentKeyId(): string;
}
