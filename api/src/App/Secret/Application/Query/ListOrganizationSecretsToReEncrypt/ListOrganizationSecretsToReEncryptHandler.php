<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt;

use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Secret\Domain\SecretCipherInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListOrganizationSecretsToReEncryptHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
        private SecretCipherInterface $cipher,
    ) {
    }

    /** @return list<string> */
    public function __invoke(ListOrganizationSecretsToReEncryptQuery $query): array
    {
        return array_map(
            static fn (OrganizationSecret $secret): string => $secret->getId()->toRfc4122(),
            $this->secrets->findNotUnderKey($this->cipher->currentKeyId()),
        );
    }
}
