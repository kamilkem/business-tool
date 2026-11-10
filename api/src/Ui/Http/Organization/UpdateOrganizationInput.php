<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use App\Organization\Application\Query\GetOrganization\OrganizationView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateOrganizationInputProvider pre-fills it from the
 * current state and API Platform applies the request body onto it, so fields the client did not
 * send keep their value.
 */
final class UpdateOrganizationInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Length(max: 32)]
    public ?string $taxId = null;

    #[Assert\NotBlank]
    #[Assert\Country]
    public string $countryCode = '';

    #[Assert\NotBlank]
    #[Assert\Currency]
    public string $currency = '';

    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timezone = '';

    public static function fromView(OrganizationView $view): self
    {
        $input = new self();
        $input->name = $view->name;
        $input->taxId = $view->taxId;
        $input->countryCode = $view->countryCode;
        $input->currency = $view->currency;
        $input->timezone = $view->timezone;

        return $input;
    }
}
