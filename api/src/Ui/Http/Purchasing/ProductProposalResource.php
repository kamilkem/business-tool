<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\ListProductProposals\ProductProposalView;
use App\Purchasing\Domain\Model\ProductMeasure;
use Ui\Http\Purchasing\Processor\AcceptProductProposalProcessor;
use Ui\Http\Purchasing\Processor\AssignProductProposalProcessor;
use Ui\Http\Purchasing\Processor\DismissProductProposalProcessor;
use Ui\Http\Purchasing\Provider\ProductProposalCollectionProvider;

/**
 * A product the model proposes for purchased items no product of the organization fits (ADR 0044), in
 * the organization selected by the X-Organization-Id header. Items that carry the same proposed name
 * (case, diacritics and punctuation aside) are one proposal, so one decision covers every spelling. A
 * proposal is never applied by itself: an owner or manager creates the product, merges the items into
 * a product that exists, or dismisses it. Owners and managers only: anyone else gets a 403.
 */
#[ApiResource(
    shortName: 'ProductProposal',
    operations: [
        new GetCollection(
            uriTemplate: '/product-proposals',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the products the model proposes for items no product fits.',
                description: 'Owners and managers only (403 otherwise). One entry per proposed name, the largest first, with the number of items and a few of their names to recognize the product by. The category is one of the organization\'s categories, or null. When a product with the proposed name exists now, `existingProductId` is its id: the proposal is that product\'s other name.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: ProductProposalCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/product-proposals/{id}/accept',
            uriVariables: ['id'],
            requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
            status: 204,
            openapi: new Operation(
                summary: 'Creates the proposed product and links the proposal\'s items to it.',
                description: 'Owners and managers only (403 otherwise). Creates a product from the `name`, `measure` and `categoryId` in the body (the proposal\'s, as the person edited them; the rules of `POST /products` apply) and links every item of the proposal to it as `confirmed`, with the package size their names state, which also teaches the aliases. The items of other proposals are put to the model again with the bigger catalog. A proposal that is not the selected organization\'s, or has no open items, is a 404.',
                responses: [
                    '204' => new Response('The product is created and the items are linked to it.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The proposal is not one of the selected organization\'s.'),
                    '409' => new Response('A product already has the name; nothing was changed.'),
                    '422' => new Response('A field is missing, wrong or too long, the reference quantity is not positive or is given for `none`, or the category is not one of the organization\'s.'),
                ],
            ),
            input: AcceptProductProposalInput::class,
            output: false,
            read: false,
            processor: AcceptProductProposalProcessor::class,
        ),
        new Post(
            uriTemplate: '/product-proposals/{id}/assign',
            uriVariables: ['id'],
            requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
            status: 204,
            openapi: new Operation(
                summary: 'Links the proposal\'s items to a product that exists.',
                description: 'Owners and managers only (403 otherwise). Every item of the proposal is linked to the product as `confirmed`, with the package size its name states, which also teaches the aliases. A proposal, or a product, that is not the selected organization\'s is a 404; nothing changes then.',
                responses: [
                    '204' => new Response('The items are linked to the product.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The proposal, or the product, is not one of the selected organization\'s.'),
                    '422' => new Response('The product id is missing or not a UUID.'),
                ],
            ),
            input: AssignProductProposalInput::class,
            output: false,
            read: false,
            processor: AssignProductProposalProcessor::class,
        ),
        new Post(
            uriTemplate: '/product-proposals/{id}/dismiss',
            uriVariables: ['id'],
            requirements: ['id' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
            status: 204,
            openapi: new Operation(
                summary: 'Dismisses a proposal.',
                description: 'Owners and managers only (403 otherwise). The items stay unassigned and the same name is not proposed for them again. A proposal that is not the selected organization\'s is a 404.',
                responses: [
                    '204' => new Response('The proposal is dismissed.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The proposal is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            output: false,
            read: false,
            processor: DismissProductProposalProcessor::class,
        ),
    ],
)]
final readonly class ProductProposalResource
{
    /**
     * @param list<string> $exampleNames
     */
    public function __construct(
        /** The proposed name's key, with hyphens: what the operations on a proposal are addressed by. */
        public string $id,
        /** The name most of the items propose. */
        public string $name,
        public ProductMeasure $measure,
        /** The category of the organization's tree the model chose; null when none. */
        public ?string $categoryId,
        public int $itemCount,
        /** A few distinct names of the items. */
        public array $exampleNames,
        /** The product that has this name now, if one does; null otherwise. */
        public ?string $existingProductId,
    ) {
    }

    public static function fromView(ProductProposalView $view): self
    {
        return new self($view->id, $view->name, $view->measure, $view->categoryId, $view->itemCount, $view->exampleNames, $view->existingProductId);
    }
}
