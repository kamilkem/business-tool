<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Purchasing\Processor\AddProductCategoryProcessor;
use Ui\Http\Purchasing\Processor\DeleteProductCategoryProcessor;
use Ui\Http\Purchasing\Processor\UpdateProductCategoryProcessor;
use Ui\Http\Purchasing\Provider\ProductCategoryCollectionProvider;
use Ui\Http\Purchasing\Provider\UpdateProductCategoryInputProvider;

/**
 * A product category of the organization selected by the X-Organization-Id header (ADR 0041 §5):
 * what the organization buys is grouped in a tree of any depth. The collection is flat, each
 * category with its `parentId`; the client builds the tree. A new organization starts with a
 * default tree for its country. Owners and managers only: anyone else gets a 403. There is no
 * archive: a category is deleted while it has no subcategory and no product.
 */
#[ApiResource(
    shortName: 'ProductCategory',
    operations: [
        new GetCollection(
            uriTemplate: '/product-categories',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the product categories of the selected organization.',
                description: 'All of them, flat, by name; each has the id of its parent (null at the top level). Owners and managers only (403 otherwise).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: ProductCategoryCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/product-categories',
            openapi: new Operation(
                summary: 'Adds a product category to the selected organization.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed, 1 to 64 characters (422), and unique among the subcategories of the same parent, or among the top level categories, compared case-insensitively (409). A `parentId` that is not one of the organization\'s categories is a 422.',
                responses: [
                    '201' => new Response('The new category.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('A category under the same parent already has the name.'),
                    '422' => new Response('The name is missing, blank or too long, or the parent is not a category of the organization.'),
                ],
            ),
            input: AddProductCategoryInput::class,
            read: false,
            processor: AddProductCategoryProcessor::class,
        ),
        new Patch(
            uriTemplate: '/product-categories/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Renames a product category and moves it in the tree.',
                description: 'Owners and managers only (403 otherwise); a category that is not the selected organization\'s is a 404. Send `name` to rename and `parentId` to move it under another category, or `null` to the top level; a field left out keeps its value. The rules of POST apply to the name and the parent (409, 422). A category cannot move under itself or one of its own subcategories (409, `purchasing.product_category_cycle`).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                    '409' => new Response('A category under the new parent has the name, or the new parent is the category itself or one of its descendants.'),
                    '422' => new Response('The name is blank or too long, or the parent is not a category of the organization.'),
                ],
            ),
            input: UpdateProductCategoryInput::class,
            provider: UpdateProductCategoryInputProvider::class,
            processor: UpdateProductCategoryProcessor::class,
        ),
        new Delete(
            uriTemplate: '/product-categories/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a product category that has no subcategory and no product.',
                description: 'Owners and managers only (403 otherwise); a category that is not the selected organization\'s is a 404. A category with a subcategory or a product cannot be deleted (409, `purchasing.product_category_in_use.has_subcategories` or `.has_products`): move or delete those first.',
                responses: [
                    '204' => new Response('The category is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                    '409' => new Response('The category has a subcategory or a product.'),
                ],
            ),
            read: false,
            processor: DeleteProductCategoryProcessor::class,
        ),
    ],
)]
final readonly class ProductCategoryResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** The category this one is under; null for a top level category. */
        public ?string $parentId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(ProductCategoryView $view): self
    {
        return new self($view->id, $view->name, $view->parentId, $view->createdAt);
    }
}
