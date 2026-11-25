<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Domain\Model\ProductMeasure;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Purchasing\Processor\CreateProductProcessor;
use Ui\Http\Purchasing\Processor\DeleteProductProcessor;
use Ui\Http\Purchasing\Processor\MergeProductProcessor;
use Ui\Http\Purchasing\Processor\UpdateProductProcessor;
use Ui\Http\Purchasing\Provider\ProductCollectionProvider;
use Ui\Http\Purchasing\Provider\ProductProvider;
use Ui\Http\Purchasing\Provider\UpdateProductInputProvider;

/**
 * A product of the organization selected by the X-Organization-Id header (ADR 0041 §4): what the
 * organization buys as the owner sees it, which purchased items are linked to. Owners and managers
 * only: anyone else gets a 403.
 */
#[ApiResource(
    shortName: 'Product',
    operations: [
        new GetCollection(
            uriTemplate: '/products',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the products of the selected organization.',
                description: 'By name; all of them, or only those in a category (and its subcategories, at any depth) and/or whose name contains a text. Owners and managers only (403 otherwise).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('The category id is not a UUID.'),
                ],
            ),
            // ListProductsInput validates the parameters; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: ProductCollectionProvider::class,
            parameters: [
                'categoryId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only the products in this category or any of its subcategories. A category that does not exist has no products.',
                ),
                'search' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'Only the products whose name contains this text, case-insensitively.',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/products/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Shows a product.',
                description: 'Owners and managers only (403 otherwise); a product that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                ],
            ),
            provider: ProductProvider::class,
        ),
        new Post(
            uriTemplate: '/products',
            openapi: new Operation(
                summary: 'Creates a product in the selected organization.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed, 1 to 128 characters (422), and unique in the organization, compared case-insensitively (409). The measure is `volume` (L), `mass` (kg), `count` (pc) or `none` (not goods: no quantity or price). The reference quantity is a positive decimal in the measure\'s base unit; left out or null it is 1, and for `none` it must be null (422). A `categoryId` that is not one of the organization\'s categories is a 422.',
                responses: [
                    '201' => new Response('The new product.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('A product already has the name.'),
                    '422' => new Response('A field is missing, wrong or too long, the reference quantity is not positive or is given for `none`, or the category is not one of the organization\'s.'),
                ],
            ),
            input: CreateProductInput::class,
            read: false,
            processor: CreateProductProcessor::class,
        ),
        new Patch(
            uriTemplate: '/products/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Changes a product: its name, category, measure, reference quantity, or whether it is excluded.',
                description: 'Owners and managers only (403 otherwise); a product that is not the selected organization\'s is a 404. A field left out keeps its value; `categoryId: null` takes the product out of its category. The rules of POST apply (409, 422). When changing the measure to `none`, send `referenceQuantity: null` too; changing it from `none` with a null reference quantity gives 1. An excluded product and every item linked to it are left out of every total.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                    '409' => new Response('Another product has the name.'),
                    '422' => new Response('A field is blank, wrong or too long, the reference quantity is not positive or does not fit the measure, or the category is not one of the organization\'s.'),
                ],
            ),
            input: UpdateProductInput::class,
            provider: UpdateProductInputProvider::class,
            processor: UpdateProductProcessor::class,
        ),
        new Post(
            uriTemplate: '/products/{id}/merge',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Merges a product into another one.',
                description: 'Owners and managers only (403 otherwise). The purchased items of the product move to `into`, each keeping its status and package size, and the product is deleted; the target keeps its own measure, reference quantity, category and excluded flag. Returns the target. A product or target that is not the selected organization\'s is a 404; merging a product into itself is a 422.',
                responses: [
                    '200' => new Response('The product that took over the items.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product, or the one to merge into, is not one of the selected organization\'s.'),
                    '422' => new Response('`into` is missing, not a UUID, or the product itself.'),
                ],
            ),
            input: MergeProductInput::class,
            read: false,
            processor: MergeProductProcessor::class,
        ),
        new Delete(
            uriTemplate: '/products/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a product that no purchased item refers to.',
                description: 'Owners and managers only (403 otherwise); a product that is not the selected organization\'s is a 404. A product with purchased items cannot be deleted (409, `purchasing.product_in_use`): merge it into another one instead.',
                responses: [
                    '204' => new Response('The product is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                    '409' => new Response('A purchased item refers to the product.'),
                ],
            ),
            read: false,
            processor: DeleteProductProcessor::class,
        ),
    ],
)]
final readonly class ProductResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** The category the product is in; null for none. */
        public ?string $categoryId,
        public ProductMeasure $measure,
        /** The quantity prices are compared per, a decimal string in the measure's base unit (L, kg, pc); null exactly when the measure is `none`. */
        public ?string $referenceQuantity,
        /** Whether the product and every item linked to it are left out of every total. */
        public bool $excluded,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(ProductView $view): self
    {
        return new self($view->id, $view->name, $view->categoryId, $view->measure, $view->referenceQuantity, $view->excluded, $view->createdAt);
    }
}
