<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Sodium;

use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\EncryptedValue;
use App\Secret\Domain\SecretCipherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * XChaCha20-Poly1305 (IETF) from libsodium with a fresh 24-byte random nonce on every write
 * (ADR 0026 §2). The keyring is parsed at the first use, so a missing or malformed
 * SECRETS_ENCRYPTION_KEYS fails the first operation on a secret, not the boot.
 */
final class SodiumSecretCipher implements SecretCipherInterface
{
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private ?SecretKeyring $keyring = null;

    public function __construct(
        #[Autowire('%env(default::SECRETS_ENCRYPTION_KEYS)%')]
        #[\SensitiveParameter]
        private readonly ?string $keyringValue,
    ) {
    }

    /** @throws InvalidSecretKeyringException */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): EncryptedValue
    {
        $keyring = $this->keyring();
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $keyring->currentKey());

        return new EncryptedValue($keyring->currentKeyId(), base64_encode($nonce), base64_encode($ciphertext));
    }

    /** @throws InvalidSecretKeyringException */
    public function decrypt(EncryptedValue $value, string $associatedData): string
    {
        $key = $this->keyring()->key($value->keyId) ?? throw SecretUnreadableException::unknownKey($value->keyId);

        $nonce = base64_decode($value->nonce, true);
        $ciphertext = base64_decode($value->ciphertext, true);
        if (false === $nonce || self::NONCE_BYTES !== \strlen($nonce) || false === $ciphertext || '' === $ciphertext) {
            throw SecretUnreadableException::malformed($value->keyId);
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $associatedData, $nonce, $key);
        } catch (\SodiumException) {
            $plaintext = false;
        }

        return false === $plaintext ? throw SecretUnreadableException::authenticationFailed($value->keyId) : $plaintext;
    }

    /** @throws InvalidSecretKeyringException */
    public function currentKeyId(): string
    {
        return $this->keyring()->currentKeyId();
    }

    /** @return array{keyring: string} */
    public function __debugInfo(): array
    {
        return ['keyring' => '*** (SECRETS_ENCRYPTION_KEYS)'];
    }

    private function keyring(): SecretKeyring
    {
        return $this->keyring ??= SecretKeyring::parse($this->keyringValue ?? '');
    }
}
