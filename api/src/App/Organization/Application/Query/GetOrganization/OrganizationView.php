<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetOrganization;

use App\Organization\Domain\Model\Organization;

final readonly class OrganizationView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $taxId,
        public string $countryCode,
        public string $currency,
        public string $timezone,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        return new self(
            $organization->getId()->toRfc4122(),
            $organization->getName(),
            $organization->getTaxId(),
            $organization->getCountryCode(),
            $organization->getCurrency(),
            $organization->getTimezone(),
            $organization->getCreatedAt(),
        );
    }
}
