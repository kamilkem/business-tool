<?php

declare(strict_types=1);

namespace Ui\Console\Secret;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a new SECRETS_ENCRYPTION_KEYS entry, `<keyId>:<base64 32-byte key>` (ADR 0026 §3). It stores
 * nothing: to rotate, put the entry first in the keyring, deploy and run app:secret:re-encrypt.
 */
#[AsCommand(name: 'app:secret:generate-key', description: 'Prints a new entry for the SECRETS_ENCRYPTION_KEYS keyring')]
final readonly class GenerateSecretKeyCommand
{
    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The key id (letters, digits, ".", "_", "-"); defaults to key-<today\'s UTC date>')]
        ?string $keyId = null,
    ): int {
        $keyId ??= 'key-'.$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd');
        if (1 !== preg_match(self::KEY_ID_PATTERN, $keyId)) {
            $io->error('A key id has letters, digits, ".", "_" or "-" only, at most 64.');

            return Command::INVALID;
        }

        $io->writeln($keyId.':'.base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()));
        $io->getErrorStyle()->note('Put it first in SECRETS_ENCRYPTION_KEYS (comma-separated), keep the older keys after it, deploy, then run app:secret:re-encrypt.');

        return Command::SUCCESS;
    }
}
