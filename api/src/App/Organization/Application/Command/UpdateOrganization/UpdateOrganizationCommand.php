<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\UpdateOrganization;

use App\Shared\Application\Command\CommandInterface;

final readonly class UpdateOrganizationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $name,
        public ?string $taxId,
        public string $countryCode,
        public string $currency,
        public string $timezone,
        /** 'comma' or 'semicolon' (CsvSeparator); null keeps the current one. */
        public ?string $csvSeparator = null,
    ) {
    }
}
