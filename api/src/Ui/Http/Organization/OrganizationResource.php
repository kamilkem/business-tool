<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Model\CsvSeparator;
use Ui\Http\Organization\Processor\CreateOrganizationProcessor;
use Ui\Http\Organization\Processor\UpdateOrganizationProcessor;
use Ui\Http\Organization\Provider\GetOrganizationProvider;
use Ui\Http\Organization\Provider\OrganizationCollectionProvider;
use Ui\Http\Organization\Provider\UpdateOrganizationInputProvider;

/** Organizations the signed-in user belongs to. */
#[ApiResource(
    shortName: 'Organization',
    operations: [
        new GetCollection(
            uriTemplate: '/organizations',
            provider: OrganizationCollectionProvider::class,
        ),
        new Get(
            uriTemplate: '/organizations/{id}',
            provider: GetOrganizationProvider::class,
        ),
        new Post(
            uriTemplate: '/organizations',
            input: CreateOrganizationInput::class,
            read: false,
            processor: CreateOrganizationProcessor::class,
        ),
        new Patch(
            uriTemplate: '/organizations/{id}',
            input: UpdateOrganizationInput::class,
            provider: UpdateOrganizationInputProvider::class,
            processor: UpdateOrganizationProcessor::class,
        ),
    ],
)]
final readonly class OrganizationResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** Tax identifier (NIP in Poland). Required for KSeF integration. */
        public ?string $taxId,
        public string $countryCode,
        public string $currency,
        public string $timezone,
        /**
         * How CSV exports are written, for the organization's spreadsheet: `semicolon` uses `;` between
         * fields and a decimal comma (1234,56), `comma` uses `,` and a decimal point (1234.56).
         */
        public CsvSeparator $csvSeparator,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(OrganizationView $view): self
    {
        return new self(
            id: $view->id,
            name: $view->name,
            taxId: $view->taxId,
            countryCode: $view->countryCode,
            currency: $view->currency,
            timezone: $view->timezone,
            csvSeparator: CsvSeparator::from($view->csvSeparator),
            createdAt: $view->createdAt,
        );
    }
}
