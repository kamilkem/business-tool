<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;
use App\Purchasing\Domain\Matching\MatchCaveat;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Purchasing\Processor\AssignPurchasedItemsProcessor;
use Ui\Http\Purchasing\Processor\ChangePurchasedItemProcessor;
use Ui\Http\Purchasing\Provider\ChangePurchasedItemInputProvider;
use Ui\Http\Purchasing\Provider\PurchasedItemProvider;

/**
 * One item of a purchase invoice of the organization selected by the X-Organization-Id header, as
 * Purchasing records it (ADR 0041 §3): what the invoice printed, which product the item is linked
 * to, how, and its package size. Owners and managers only: anyone else gets a 403. Listed by
 * GET /purchased-items (PurchasedItemListResource).
 */
#[ApiResource(
    shortName: 'PurchasedItem',
    operations: [
        new Get(
            uriTemplate: '/purchased-items/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Shows a purchased item.',
                description: 'Owners and managers only (403 otherwise); an item that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The item is not one of the selected organization\'s.'),
                ],
            ),
            provider: PurchasedItemProvider::class,
        ),
        new Patch(
            uriTemplate: '/purchased-items/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Links a purchased item to a product, takes it off its product, or sets its package size.',
                description: 'Owners and managers only (403 otherwise); an item that is not the selected organization\'s is a 404. A field left out keeps its value; `productId: null` takes the item off its product, `packageSize: null` clears the package size. Any change by a person confirms the link: the item becomes `confirmed` with a product (also when only the package size changes, or nothing does, which confirms a suggestion) and `unassigned` without one. The package size is a positive decimal in the product\'s base unit (L, kg, pc) per one unit of the item\'s quantity, with at most 6 decimals (422). A `productId` that is not one of the organization\'s products is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The item, or the product to link it to, is not one of the selected organization\'s.'),
                    '422' => new Response('The product id is not a UUID, or the package size is not a positive decimal with at most 6 decimals.'),
                ],
            ),
            input: ChangePurchasedItemInput::class,
            provider: ChangePurchasedItemInputProvider::class,
            processor: ChangePurchasedItemProcessor::class,
        ),
        new Post(
            uriTemplate: '/purchased-items/assign',
            status: 204,
            openapi: new Operation(
                summary: 'Links several purchased items to one product: the review queue\'s bulk action.',
                description: 'Owners and managers only (403 otherwise). All or nothing: when an item, or the product, is not one of the selected organization\'s (another organization\'s is not found either), the answer is a 404 and no item changes. Every item becomes `confirmed`. With a `packageSize` every item gets it; left out or null, each item keeps its own. At most '.AssignPurchasedItemsCommand::MAX_ITEMS.' distinct items at once (422). Creating a product from an item is `POST /products` followed by this call.',
                responses: [
                    '204' => new Response('The items are linked to the product.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('An item, or the product, is not one of the selected organization\'s; nothing was changed.'),
                    '422' => new Response('The item ids are missing, empty, too many, repeated or not UUIDs, the product id is missing or not a UUID, or the package size is not a positive decimal with at most 6 decimals.'),
                ],
            ),
            input: AssignPurchasedItemsInput::class,
            output: false,
            read: false,
            processor: AssignPurchasedItemsProcessor::class,
        ),
    ],
)]
final readonly class PurchasedItemResource
{
    public function __construct(
        public string $id,
        /** Expenses' invoice the item is on. */
        public string $invoiceId,
        /** The invoice's number, as Expenses has it now; null for an invoice without one (a receipt). */
        public ?string $invoiceNumber,
        /** The invoice's issue date, `YYYY-MM-DD`. */
        public string $invoiceIssueDate,
        /** `regular`, `correction`, `advance`, `settlement`, `simplified`, `advance_correction` or `settlement_correction`. */
        public string $invoiceKind,
        /** ISO 4217 code of the amounts. */
        public string $currency,
        public ?string $sellerTaxId,
        public string $sellerName,
        /** The row number printed on the invoice; not unique. */
        public int $position,
        /** The item as printed. */
        public string $name,
        /** The seller's own code for it. */
        public ?string $sellerCode,
        public ?string $gtin,
        /** The unit as printed (`szt.`, `l`, `kg`). */
        public ?string $unit,
        /** A decimal string, as printed. */
        public ?string $quantity,
        /** A decimal string, as printed. */
        public ?string $unitNetPrice,
        /** A decimal string, as printed. */
        public ?string $unitGrossPrice,
        /** A decimal string, as printed. */
        public ?string $discount,
        /** In minor units of the invoice's currency; negative for a correction's row. */
        public ?int $netAmount,
        public ?int $grossAmount,
        public ?int $vatAmount,
        public ?string $vatRate,
        /** In a correction, the row as it was before the correction. */
        public bool $beforeCorrection,
        /** The product it is linked to; null until it is. */
        public ?string $productId,
        public ?string $productName,
        /** `unassigned`, `suggested` (by matching, to confirm), `assigned` (by matching) or `confirmed` (by a person). */
        public PurchasedItemStatus $status,
        /** In the product's base unit (L, kg, pc) per one unit of the item's quantity, a decimal string; null until known. */
        public ?string $packageSize,
        /** What matching linked the item by (ADR 0045 §1): `gtin`, `seller_code`, `seller_name` or `name` (the owner's remembered alias of that kind), `model`, or `proposed_name` (the model proposed a product by a name the catalog has); null when a person linked it, it is unassigned, or it was matched before this was kept. */
        public ?MatchSource $matchSource,
        /** Why matching only suggested the product: `aliases_conflict`, `model_unsure`, `proposed_name_only`, `measure_mismatch`, `size_unknown`, `size_read_weakly`, `size_disagrees`, `size_unverified` or `price_outlier` (the price per base unit is far from the product's median); null for any other status. */
        public ?MatchCaveat $matchCaveat,
    ) {
    }

    public static function fromView(PurchasedItemView $view): self
    {
        return new self(
            $view->id,
            $view->invoiceId,
            $view->invoiceNumber,
            $view->invoiceIssueDate,
            $view->invoiceKind,
            $view->currency,
            $view->sellerTaxId,
            $view->sellerName,
            $view->position,
            $view->name,
            $view->sellerCode,
            $view->gtin,
            $view->unit,
            $view->quantity,
            $view->unitNetPrice,
            $view->unitGrossPrice,
            $view->discount,
            $view->netAmount,
            $view->grossAmount,
            $view->vatAmount,
            $view->vatRate,
            $view->beforeCorrection,
            $view->productId,
            $view->productName,
            $view->status,
            $view->packageSize,
            $view->matchSource,
            $view->matchCaveat,
        );
    }
}
