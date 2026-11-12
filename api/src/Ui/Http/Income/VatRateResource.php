<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\ListVatRates\VatRateView;
use Ui\Http\Income\Provider\VatRateCollectionProvider;

/** A VAT rate a revenue entry's breakdown may use in the selected organization, by its country (ADR 0022 §6). */
#[ApiResource(
    shortName: 'VatRate',
    operations: [
        new GetCollection(
            uriTemplate: '/vat-rates',
            paginationEnabled: false,
            openapi: new Operation(
                summary: "The VAT rates of the selected organization's country.",
                description: 'Owners and managers only (403 otherwise). In the order a daily report prints them; an empty list for a country without rates, where entries take no VAT lines.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: VatRateCollectionProvider::class,
        ),
    ],
)]
final readonly class VatRateResource
{
    public function __construct(
        /** The rate as a fiscal printer labels it: "23", "zw". */
        public string $code,
        /** Sales at this rate carry no VAT (0% and exempt): their vat must be 0. */
        public bool $zeroVat,
    ) {
    }

    public static function fromView(VatRateView $view): self
    {
        return new self($view->code, $view->zeroVat);
    }
}
