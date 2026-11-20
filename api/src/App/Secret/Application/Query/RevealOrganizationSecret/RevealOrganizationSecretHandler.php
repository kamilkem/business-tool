<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\RevealOrganizationSecret;

use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Secret\Domain\SecretCipherInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RevealOrganizationSecretHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
        private SecretCipherInterface $cipher,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws SecretUnreadableException when the value does not decrypt (ADR 0026 §7) */
    public function __invoke(RevealOrganizationSecretQuery $query): ?RevealedSecretView
    {
        $secret = Uuid::isValid($query->secretId) ? $this->secrets->findById(Uuid::fromString($query->secretId)) : null;
        if (null === $secret || SecretKind::tryFrom($query->expectedKind) !== $secret->getKind()) {
            return null;
        }

        $associatedData = OrganizationSecret::associatedData(Uuid::fromString($query->organizationId), $secret->getId(), $secret->getKind());
        try {
            $value = $this->cipher->decrypt($secret->getEncryptedValue(), $associatedData);
        } catch (SecretUnreadableException $e) {
            $this->logger->error('Organization secret cannot be read.', [
                'organization_id' => $query->organizationId,
                'secret_id' => $query->secretId,
                'kind' => $secret->getKind()->value,
                'key_id' => $secret->getKeyId(),
                'reason' => $e->getMessage(),
            ]);

            throw SecretUnreadableException::ofSecret($query->secretId, $e);
        }

        return new RevealedSecretView($query->secretId, $secret->getKind()->value, $value);
    }
}
