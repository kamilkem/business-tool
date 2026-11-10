<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrganizationInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        /** Tax identifier (NIP in Poland). Needed for KSeF integration. */
        #[Assert\Length(max: 32)]
        public ?string $taxId = null,
        #[Assert\NotBlank]
        #[Assert\Country]
        public string $countryCode = 'PL',
        #[Assert\NotBlank]
        #[Assert\Currency]
        public string $currency = 'PLN',
        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = 'Europe/Warsaw',
    ) {
    }
}
