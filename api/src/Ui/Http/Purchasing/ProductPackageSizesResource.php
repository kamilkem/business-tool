<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeCommand;
use App\Purchasing\Application\Query\GetProductPackageSizes\ProductPackageSizesView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Purchasing\Processor\ApplyPackageSizeProcessor;
use Ui\Http\Purchasing\Provider\ProductPackageSizesProvider;

/**
 * A product's items by unit and package size, and the owner's answer to "the same size on the other items?"
 * (ADR 0049). Owners and managers only.
 */
#[ApiResource(
    shortName: 'ProductPackageSizes',
    operations: [
        new Get(
            uriTemplate: '/products/{id}/package-sizes',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'The items of a product by unit and package size.',
                description: 'Owners and managers only (403 otherwise); a product that is not the selected organization\'s is a 404. One group per unit (`szt.` and `SZT` are one), package size and bundle count, whatever the items\' status, with how many items it holds and how many of them a person confirmed. What the dialog that sets a package size asks about: the groups with no size under other units, and the groups with another size.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                ],
            ),
            provider: ProductPackageSizesProvider::class,
        ),
        new Post(
            uriTemplate: '/products/{id}/package-sizes',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 204,
            openapi: new Operation(
                summary: 'Sets one package size on the product\'s items of the chosen groups.',
                description: 'Owners and managers only (403 otherwise). Writes only the package of the items of the product that have the unit, package size and bundle count of one of the `groups` (as `GET` lists them): the `packageSize` with the `bundleCount` of the body (null: no bundle; for a bundle the size is that of one single package, ADR 0048); their product, status and link stay as they are, so a confirmed link is never changed. Nothing is applied that the person did not choose. A group that holds no item any more changes nothing. At most '.ApplyPackageSizeCommand::MAX_TARGETS.' groups (422). A product that is not the selected organization\'s is a 404 and nothing changes.',
                responses: [
                    '204' => new Response('The package size is set on the items of the groups.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                    '422' => new Response('The package size is missing or not a positive decimal with at most 6 decimals, the bundle count is not a whole number from 2 to 9999 or makes the size per unit too large, or a group is missing, has a size or count that is not valid, or there are too many.'),
                ],
            ),
            input: ApplyPackageSizeInput::class,
            output: false,
            read: false,
            processor: ApplyPackageSizeProcessor::class,
        ),
    ],
)]
final readonly class ProductPackageSizesResource
{
    /** @param list<ProductPackageSizeGroup> $groups */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $productId,
        /** By unit (no unit last), then the items with no package size first, then by size. */
        #[ApiProperty(readableLink: true)]
        public array $groups,
    ) {
    }

    public static function fromView(ProductPackageSizesView $view): self
    {
        return new self($view->productId, array_map(ProductPackageSizeGroup::fromGroup(...), $view->groups));
    }
}
