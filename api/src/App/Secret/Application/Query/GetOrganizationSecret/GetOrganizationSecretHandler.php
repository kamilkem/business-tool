<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\GetOrganizationSecret;

use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetOrganizationSecretHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
    ) {
    }

    public function __invoke(GetOrganizationSecretQuery $query): ?OrganizationSecretView
    {
        $secret = Uuid::isValid($query->secretId) ? $this->secrets->findById(Uuid::fromString($query->secretId)) : null;

        return null === $secret ? null : OrganizationSecretView::fromSecret($secret);
    }
}
