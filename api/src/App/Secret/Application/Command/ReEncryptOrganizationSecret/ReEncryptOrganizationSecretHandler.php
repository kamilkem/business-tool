<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\ReEncryptOrganizationSecret;

use App\Secret\Domain\Exception\OrganizationSecretNotFoundException;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Secret\Domain\SecretCipherInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ReEncryptOrganizationSecretHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
        private SecretCipherInterface $cipher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OrganizationSecretNotFoundException when the secret is not one of the organization's
     * @throws SecretUnreadableException           when the value does not decrypt with any key of the keyring
     */
    public function __invoke(ReEncryptOrganizationSecretCommand $command): void
    {
        $secret = Uuid::isValid($command->secretId) ? $this->secrets->findById(Uuid::fromString($command->secretId)) : null;
        $secret ??= throw OrganizationSecretNotFoundException::withId($command->secretId);

        $previousKeyId = $secret->getKeyId();
        if ($previousKeyId === $this->cipher->currentKeyId()) {
            return;
        }

        $associatedData = OrganizationSecret::associatedData(Uuid::fromString($command->organizationId), $secret->getId(), $secret->getKind());
        try {
            $plaintext = $this->cipher->decrypt($secret->getEncryptedValue(), $associatedData);
        } catch (SecretUnreadableException $e) {
            $this->logger->error('Organization secret cannot be read for re-encryption.', [
                'organization_id' => $command->organizationId,
                'secret_id' => $command->secretId,
                'kind' => $secret->getKind()->value,
                'key_id' => $previousKeyId,
                'reason' => $e->getMessage(),
            ]);

            throw SecretUnreadableException::ofSecret($command->secretId, $e);
        }

        $value = $this->cipher->encrypt($plaintext, $associatedData);
        $secret->reEncrypt($value);
        $this->secrets->save($secret);

        $this->logger->info('Organization secret re-encrypted.', [
            'organization_id' => $command->organizationId,
            'secret_id' => $command->secretId,
            'kind' => $secret->getKind()->value,
            'previous_key_id' => $previousKeyId,
            'key_id' => $value->keyId,
        ]);
    }
}
