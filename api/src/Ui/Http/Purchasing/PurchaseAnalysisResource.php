<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\GetPurchaseAnalysisQuery;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\PurchaseAnalysisView;
use Ui\Http\Purchasing\Provider\PurchaseAnalysisProvider;

/**
 * The overview of what the organization selected by the X-Organization-Id header bought in a period
 * (ADR 0041 §6–8): the category tree and the products with their spend, quantities and prices, the
 * items without a product or a category, and what was left out. Owners and managers only.
 */
#[ApiResource(
    shortName: 'PurchaseAnalysis',
    operations: [
        new Get(
            uriTemplate: '/purchase-analysis',
            openapi: new Operation(
                summary: 'The purchase analysis of the selected organization for invoices issued from one date to another.',
                description: 'Owners and managers only (403 otherwise). Counts the purchased items of invoices issued in the period, in the organization\'s currency, of products that are not excluded; a correction\'s "before" rows count negative. Amounts are integer minor units on both bases: `net` is the printed net or the gross less VAT, `gross` the printed gross or the net plus VAT. `categories` holds every category, by name, as a flat list with `parentId` (own spend and spend with all subcategories); `products` those with items in the period, by name; `uncategorized` is the spend of listed products with no category and `notAssigned` that of items with no product; `total` adds up all products and `notAssigned`. For a product with a unit, `quantity`, the average and the last price come from its price points and are per `referenceQuantity`; a product without a unit has its spend only. `leftOut` counts what could not be counted. Invalid dates, to before from and periods longer than '.GetPurchaseAnalysisQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A date is missing or invalid, to is before from, or the period is too long.'),
                ],
            ),
            // PurchaseAnalysisInput validates the parameters, the period rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: PurchaseAnalysisProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First issue date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last issue date, inclusive (YYYY-MM-DD); at most '.GetPurchaseAnalysisQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
            ],
        ),
    ],
)]
final readonly class PurchaseAnalysisResource
{
    /**
     * @param list<PurchaseAnalysisCategory> $categories
     * @param list<PurchaseAnalysisProduct>  $products
     */
    public function __construct(
        /** First issue date of the period, `YYYY-MM-DD`. */
        public string $from,
        /** Last issue date of the period, inclusive, `YYYY-MM-DD`. */
        public string $to,
        /** ISO 4217 code of the organization's currency: the only one counted. */
        public string $currency,
        /** Everything counted: all products and the items without a product. */
        #[ApiProperty(readableLink: true)]
        public PurchaseAnalysisAmounts $total,
        /** Every category of the organization, by name; `parentId` builds the tree. */
        #[ApiProperty(readableLink: true)]
        public array $categories,
        /** The products with items in the period, by name; `categoryId` places them in the tree. */
        #[ApiProperty(readableLink: true)]
        public array $products,
        /** The products in no category: their spend and how many they are. */
        #[ApiProperty(readableLink: true)]
        public PurchaseAnalysisBucket $uncategorized,
        /** Items linked to no product: their spend and how many they are (the review queue holds them). */
        #[ApiProperty(readableLink: true)]
        public PurchaseAnalysisBucket $notAssigned,
        #[ApiProperty(readableLink: true)]
        public PurchaseAnalysisLeftOut $leftOut,
    ) {
    }

    public static function fromView(PurchaseAnalysisView $view): self
    {
        $analysis = $view->analysis;

        return new self(
            $view->from,
            $view->to,
            $view->currency,
            PurchaseAnalysisAmounts::fromDomain($view->total),
            array_map(PurchaseAnalysisCategory::fromDomain(...), $analysis->categories),
            array_map(PurchaseAnalysisProduct::fromDomain(...), $analysis->products),
            PurchaseAnalysisBucket::fromDomain($view->uncategorizedSpend, $view->uncategorizedProducts),
            PurchaseAnalysisBucket::fromDomain($analysis->notAssignedSpend, $analysis->notAssignedItems),
            new PurchaseAnalysisLeftOut(
                $analysis->otherCurrencyItems,
                $analysis->itemsWithoutNet,
                $analysis->itemsWithoutGross,
                $analysis->itemsWithoutPackageSize,
            ),
        );
    }
}
