<?php

declare(strict_types=1);

namespace Ui\Console\Secret;

use App\Secret\Application\Rotation\ReEncryptSecrets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-encrypts every organization's secrets that are not under the current key, the first of
 * SECRETS_ENCRYPTION_KEYS (ADR 0026 §4). Safe to run again; the old key can leave the keyring once it
 * reports nothing left.
 */
#[AsCommand(name: 'app:secret:re-encrypt', description: 'Re-encrypts organization secrets with the current key of SECRETS_ENCRYPTION_KEYS')]
final readonly class ReEncryptSecretsCommand
{
    public function __construct(
        private ReEncryptSecrets $reEncryptSecrets,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = ($this->reEncryptSecrets)();

        $io->writeln(\sprintf('Organizations: %d. Secrets re-encrypted: %d.', $report->organizations, $report->reEncrypted));
        if ([] !== $report->unreadableSecretIds) {
            $io->error(\sprintf(
                "%d secret(s) do not decrypt with any key of the keyring; their owners have to save them again:\n%s",
                \count($report->unreadableSecretIds),
                implode("\n", $report->unreadableSecretIds),
            ));

            return Command::FAILURE;
        }

        $io->success('Every secret is under the current key. Keys no longer in use can leave the keyring.');

        return Command::SUCCESS;
    }
}
