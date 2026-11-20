<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Sodium;

/**
 * The application keyring of SECRETS_ENCRYPTION_KEYS (ADR 0026 §3): comma-separated
 * `<keyId>:<base64 32-byte key>` entries. The first one encrypts; every one decrypts. The keys are
 * kept out of dumps and cannot be serialized.
 */
final class SecretKeyring
{

    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @param non-empty-array<string, string> $keys key id => raw key, the current one first */
    private function __construct(private readonly array $keys)
    {
    }

    /** @throws InvalidSecretKeyringException */
    public static function parse(#[\SensitiveParameter] string $keyring): self
    {
        $keys = [];
        foreach (explode(',', $keyring) as $index => $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            $parts = explode(':', $entry, 2);
            if (2 !== \count($parts) || 1 !== preg_match(self::KEY_ID_PATTERN, $parts[0])) {
                throw InvalidSecretKeyringException::malformedEntry($index + 1);
            }
            [$keyId, $encodedKey] = $parts;
            if (isset($keys[$keyId])) {
                throw InvalidSecretKeyringException::duplicateKeyId($keyId);
            }

            $key = base64_decode($encodedKey, true);
            if (false === $key || self::keyLength() !== \strlen($key)) {
                throw InvalidSecretKeyringException::invalidKey($keyId, self::keyLength());
            }
            $keys[$keyId] = $key;
        }

        if ([] === $keys) {
            throw InvalidSecretKeyringException::empty();
        }

        return new self($keys);
    }

    /** Bytes of a key: libsodium's XChaCha20-Poly1305 IETF key size (32). */
    public static function keyLength(): int
    {
        return SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    }

    public function currentKeyId(): string
    {
        return array_key_first($this->keys);
    }

    public function currentKey(): string
    {
        return $this->keys[$this->currentKeyId()];
    }

    public function key(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }

    /** @return array{keyIds: list<string>} */
    public function __debugInfo(): array
    {
        return ['keyIds' => array_keys($this->keys)];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('The secrets keyring cannot be serialized.');
    }
}
