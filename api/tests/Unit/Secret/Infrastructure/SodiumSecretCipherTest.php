<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Infrastructure;

use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\EncryptedValue;
use App\Secret\Infrastructure\Sodium\InvalidSecretKeyringException;
use App\Secret\Infrastructure\Sodium\SecretKeyring;
use App\Secret\Infrastructure\Sodium\SodiumSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real libsodium cipher with fixed, obviously fake test keys (adr/0026 §8). */
#[CoversClass(SodiumSecretCipher::class)]
#[CoversClass(SecretKeyring::class)]
#[CoversClass(InvalidSecretKeyringException::class)]
#[CoversClass(SecretUnreadableException::class)]
final class SodiumSecretCipherTest extends TestCase
{
    private const string ASSOCIATED_DATA = 'v1|0195a0a0-0000-7000-8000-000000000001|0195a0a0-0000-7000-8000-000000000002|ksef_token';
    private const string PLAINTEXT = 'fake-ksef-token|nip-1111111111|not-a-real-token';

    private static function entry(string $keyId, string $fill): string
    {
        return $keyId.':'.base64_encode(str_repeat($fill, SecretKeyring::keyLength()));
    }

    public function test_it_decrypts_what_it_encrypted(): void
    {
        $cipher = new SodiumSecretCipher(self::entry('k1', 'a'));

        $value = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        self::assertSame('k1', $value->keyId);
        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, \strlen(base64_decode($value->nonce, true) ?: ''));
        self::assertStringNotContainsString(self::PLAINTEXT, (string) base64_decode($value->ciphertext, true));
        self::assertSame(self::PLAINTEXT, $cipher->decrypt($value, self::ASSOCIATED_DATA));
    }

    public function test_it_uses_a_fresh_nonce_on_every_write(): void
    {
        $cipher = new SodiumSecretCipher(self::entry('k1', 'a'));

        $first = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);
        $second = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        self::assertNotSame($first->nonce, $second->nonce);
        self::assertNotSame($first->ciphertext, $second->ciphertext);
    }

    public function test_it_refuses_a_value_under_another_key_with_the_same_id(): void
    {
        $value = (new SodiumSecretCipher(self::entry('k1', 'a')))->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        $this->expectException(SecretUnreadableException::class);
        $this->expectExceptionMessage('failed authentication');

        (new SodiumSecretCipher(self::entry('k1', 'b')))->decrypt($value, self::ASSOCIATED_DATA);
    }

    /** @return iterable<string, array{string}> */
    public static function otherAssociatedData(): iterable
    {
        yield 'another organization' => ['v1|0195a0a0-0000-7000-8000-000000000009|0195a0a0-0000-7000-8000-000000000002|ksef_token'];
        yield 'another secret' => ['v1|0195a0a0-0000-7000-8000-000000000001|0195a0a0-0000-7000-8000-000000000009|ksef_token'];
        yield 'another kind' => ['v1|0195a0a0-0000-7000-8000-000000000001|0195a0a0-0000-7000-8000-000000000002|ksef_certificate'];
        yield 'another version' => ['v2|0195a0a0-0000-7000-8000-000000000001|0195a0a0-0000-7000-8000-000000000002|ksef_token'];
    }

    #[DataProvider('otherAssociatedData')]
    public function test_it_refuses_a_value_moved_to_another_row(string $associatedData): void
    {
        $cipher = new SodiumSecretCipher(self::entry('k1', 'a'));
        $value = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        $this->expectException(SecretUnreadableException::class);

        $cipher->decrypt($value, $associatedData);
    }

    public function test_it_refuses_an_altered_ciphertext(): void
    {
        $cipher = new SodiumSecretCipher(self::entry('k1', 'a'));
        $value = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);
        $bytes = (string) base64_decode($value->ciphertext, true);
        $bytes[0] = \chr(\ord($bytes[0]) ^ 1);

        $this->expectException(SecretUnreadableException::class);

        $cipher->decrypt(new EncryptedValue($value->keyId, $value->nonce, base64_encode($bytes)), self::ASSOCIATED_DATA);
    }

    public function test_it_refuses_a_malformed_nonce(): void
    {
        $cipher = new SodiumSecretCipher(self::entry('k1', 'a'));
        $value = $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        $this->expectException(SecretUnreadableException::class);
        $this->expectExceptionMessage('malformed');

        $cipher->decrypt(new EncryptedValue($value->keyId, base64_encode('short'), $value->ciphertext), self::ASSOCIATED_DATA);
    }

    public function test_it_reports_a_key_id_missing_from_the_keyring(): void
    {
        $value = (new SodiumSecretCipher(self::entry('old', 'a')))->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        $this->expectException(SecretUnreadableException::class);
        $this->expectExceptionMessage('key "old", which is not in the keyring');

        (new SodiumSecretCipher(self::entry('new', 'b')))->decrypt($value, self::ASSOCIATED_DATA);
    }

    public function test_it_encrypts_with_the_first_key_and_decrypts_with_any(): void
    {
        $old = new SodiumSecretCipher(self::entry('old', 'a'));
        $underOldKey = $old->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);

        $rotated = new SodiumSecretCipher(self::entry('new', 'b').','.self::entry('old', 'a'));
        $underNewKey = $rotated->encrypt($rotated->decrypt($underOldKey, self::ASSOCIATED_DATA), self::ASSOCIATED_DATA);

        self::assertSame('new', $rotated->currentKeyId());
        self::assertSame('new', $underNewKey->keyId);
        self::assertSame(self::PLAINTEXT, $rotated->decrypt($underNewKey, self::ASSOCIATED_DATA));
        self::assertSame(self::PLAINTEXT, (new SodiumSecretCipher(self::entry('new', 'b')))->decrypt($underNewKey, self::ASSOCIATED_DATA));
    }

    public function test_it_ignores_blanks_around_entries(): void
    {
        $cipher = new SodiumSecretCipher(' '.self::entry('k1', 'a').' , '.self::entry('k0', 'b').' ,');

        self::assertSame('k1', $cipher->currentKeyId());
    }

    /** @return iterable<string, array{?string, string}> */
    public static function malformedKeyrings(): iterable
    {
        yield 'not set' => [null, 'is empty'];
        yield 'empty' => ['', 'is empty'];
        yield 'only commas' => [' , ', 'is empty'];
        yield 'no key id' => [base64_encode(str_repeat('a', 32)), 'Entry 1'];
        yield 'blank key id' => [':'.base64_encode(str_repeat('a', 32)), 'Entry 1'];
        yield 'key id with a space' => ['my key:'.base64_encode(str_repeat('a', 32)), 'Entry 1'];
        yield 'not base64' => ['k1:not base64!', 'Key "k1"'];
        yield 'key too short' => ['k1:'.base64_encode(str_repeat('a', 16)), 'Key "k1"'];
        yield 'key too long' => ['k1:'.base64_encode(str_repeat('a', 33)), 'Key "k1"'];
        yield 'second entry malformed' => [self::entry('k1', 'a').',k2', 'Entry 2'];
        yield 'duplicate key id' => [self::entry('k1', 'a').','.self::entry('k1', 'b'), 'Key id "k1" appears more than once'];
    }

    #[DataProvider('malformedKeyrings')]
    public function test_it_fails_loudly_at_the_first_use_of_a_malformed_keyring(?string $keyring, string $message): void
    {
        $cipher = new SodiumSecretCipher($keyring);

        $this->expectException(InvalidSecretKeyringException::class);
        $this->expectExceptionMessage($message);

        $cipher->encrypt(self::PLAINTEXT, self::ASSOCIATED_DATA);
    }

    public function test_it_never_puts_a_key_in_messages_or_dumps(): void
    {
        $key = str_repeat('k', 16);
        try {
            SecretKeyring::parse('k1:'.base64_encode($key));
            self::fail('Exception expected.');
        } catch (InvalidSecretKeyringException $e) {
            self::assertStringNotContainsString(base64_encode($key), $e->getMessage());
        }

        $entry = self::entry('k1', 'a');
        $cipher = new SodiumSecretCipher($entry);
        $cipher->currentKeyId();

        self::assertStringNotContainsString(base64_encode(str_repeat('a', 32)), print_r($cipher, true));
        self::assertStringNotContainsString(str_repeat('a', 32), print_r(SecretKeyring::parse($entry), true));
    }

    public function test_it_refuses_to_serialize_the_keyring(): void
    {
        $this->expectException(\LogicException::class);

        serialize(SecretKeyring::parse(self::entry('k1', 'a')));
    }
}
