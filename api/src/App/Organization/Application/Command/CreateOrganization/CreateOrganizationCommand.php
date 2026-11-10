<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\CreateOrganization;

use App\Shared\Application\Command\CommandInterface;

final readonly class CreateOrganizationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $ownerId,
        public string $name,
        public ?string $taxId,
        public string $countryCode,
        public string $currency,
        public string $timezone,
    ) {
    }
}
