<?php

declare(strict_types=1);

namespace App\Secret\Domain\Exception;

/**
 * A stored secret does not decrypt: its key is no longer in the keyring, the keyring was lost, or the
 * row was altered (ADR 0026 §7). Its consumer treats it as a missing secret (KSeF:
 * `credentials_rejected`), and the owner saves the value again.
 */
final class SecretUnreadableException extends SecretException
{
    public static function unknownKey(string $keyId): self
    {
        return new self(\sprintf('The value is encrypted with key "%s", which is not in the keyring.', $keyId), ['keyId' => $keyId], variant: __FUNCTION__);
    }

    public static function authenticationFailed(string $keyId): self
    {
        return new self(\sprintf('The value encrypted with key "%s" failed authentication: the key, the value or its associated data differs.', $keyId), ['keyId' => $keyId], variant: __FUNCTION__);
    }

    public static function malformed(string $keyId): self
    {
        return new self(\sprintf('The value encrypted with key "%s" has a malformed nonce or ciphertext.', $keyId), ['keyId' => $keyId], variant: __FUNCTION__);
    }

    public static function ofSecret(string $secretId, self $previous): self
    {
        return new self(\sprintf('Secret "%s" cannot be read. %s', $secretId, $previous->getMessage()), ['secretId' => $secretId], __FUNCTION__, $previous);
    }
}
