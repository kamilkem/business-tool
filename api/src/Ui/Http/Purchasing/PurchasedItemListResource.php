<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\ListPurchasedItems\ListPurchasedItemsQuery;
use App\Purchasing\Application\Query\ListPurchasedItems\PurchasedItemPageView;
use Ui\Http\Purchasing\Provider\PurchasedItemListProvider;

/**
 * A page of the purchased items of the organization selected by the X-Organization-Id header (ADR
 * 0041 §3): the review queue lists the `unassigned` and `suggested` ones, the product detail the ones
 * linked to the product. Owners and managers only.
 */
#[ApiResource(
    shortName: 'PurchasedItemList',
    operations: [
        new Get(
            uriTemplate: '/purchased-items',
            openapi: new Operation(
                summary: 'Lists the purchased items of the selected organization, a page at a time.',
                description: 'Owners and managers only (403 otherwise). Newest invoice issue date first, then by seller name, invoice and position in the document. Every filter that is set must hold; `total` counts the items all pages hold. Invalid filters, dates, or a page outside the limits are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A filter is invalid: an unknown status, a product id that is not a UUID, a date that is not valid, `to` before `from`, or a page or page size outside the limits.'),
                ],
            ),
            // ListPurchasedItemsInput validates the parameters; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: PurchasedItemListProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['unassigned', 'suggested', 'assigned', 'confirmed']]],
                    description: 'Only the items in any of these states, comma-separated (`unassigned,suggested`); `status[]=` repeated works too.',
                    openApi: new Parameter(name: 'status', in: 'query', style: 'form', explode: false),
                ),
                'productId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only the items linked to this product.',
                ),
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Only the items of invoices issued on this date (YYYY-MM-DD) or later.',
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Only the items of invoices issued on this date (YYYY-MM-DD) or earlier.',
                ),
                'search' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'Only the items whose name, as printed, contains this text, case-insensitively.',
                ),
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    description: 'The page, from 1.',
                ),
                'itemsPerPage' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => ListPurchasedItemsQuery::MAX_PAGE_SIZE, 'default' => ListPurchasedItemsQuery::DEFAULT_PAGE_SIZE],
                    description: 'How many items a page holds.',
                ),
            ],
        ),
    ],
)]
final readonly class PurchasedItemListResource
{
    /** @param list<PurchasedItemResource> $items */
    public function __construct(
        /** The page's items. */
        #[ApiProperty(readableLink: true)]
        public array $items,
        /** How many items the filters select in all pages. */
        public int $total,
        /** The page, from 1. */
        public int $page,
        public int $itemsPerPage,
    ) {
    }

    public static function fromView(PurchasedItemPageView $view): self
    {
        return new self(
            array_map(PurchasedItemResource::fromView(...), $view->items),
            $view->total,
            $view->page,
            $view->pageSize,
        );
    }
}
