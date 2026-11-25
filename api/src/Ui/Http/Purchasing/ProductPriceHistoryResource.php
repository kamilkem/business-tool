<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\GetProductPriceHistory\ProductPriceHistoryView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Purchasing\Provider\ProductPriceHistoryProvider;

/**
 * The price points of one product of the organization selected by the X-Organization-Id header (ADR
 * 0041 §7), for the product detail's price chart. Owners and managers only.
 */
#[ApiResource(
    shortName: 'ProductPriceHistory',
    operations: [
        new Get(
            uriTemplate: '/products/{id}/price-history',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'The price points of a product, oldest first.',
                description: 'Owners and managers only (403 otherwise); a product that is not the selected organization\'s is a 404. A price point is a purchased item with a known package size and a positive quantity, in the organization\'s currency, on an invoice that is not a correction; prices are per the product\'s reference quantity, integer minor units. Each point\'s change is against the product\'s previous price point on the same basis, from any seller, also when that one is before `from`. `from` and `to` are optional and not limited in length; a product without a unit or an excluded one has no points. Invalid dates and to before from are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The product is not one of the selected organization\'s.'),
                    '422' => new Response('A date is invalid or to is before from.'),
                ],
            ),
            // PriceHistoryInput validates the parameters; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: ProductPriceHistoryProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Only the purchases on invoices issued on this date (YYYY-MM-DD) or later.',
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Only the purchases on invoices issued on this date (YYYY-MM-DD) or earlier.',
                ),
            ],
        ),
    ],
)]
final readonly class ProductPriceHistoryResource
{
    /** @param list<ProductPricePoint> $points */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $productId,
        public string $productName,
        /** `volume` (L), `mass` (kg), `count` (pc) or `none` (no points). */
        public string $measure,
        /** The quantity the prices are per, a decimal string in the base unit; null when the measure is `none`. */
        public ?string $referenceQuantity,
        /** ISO 4217 code of the organization's currency: the only one counted. */
        public string $currency,
        /** The requested first issue date, `YYYY-MM-DD`; null when not given. */
        public ?string $from,
        public ?string $to,
        /** The price points, oldest first (by issue date, then when the invoice was stored). */
        #[ApiProperty(readableLink: true)]
        public array $points,
    ) {
    }

    public static function fromView(ProductPriceHistoryView $view): self
    {
        return new self(
            $view->productId,
            $view->productName,
            $view->measure->value,
            $view->referenceQuantity,
            $view->currency,
            $view->from,
            $view->to,
            array_map(ProductPricePoint::fromView(...), $view->points),
        );
    }
}
