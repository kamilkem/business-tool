<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Analysis\PurchaseAnalysisData;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * The aggregations of the purchase analysis (ADR 0041 §6–8) for the current organization. They run in
 * the database on exact decimals (the adapter filters by organization itself, as the ORM's TenantFilter
 * does not apply to its SQL) and round to minor units only in their result.
 */
interface PurchaseAnalysisRepositoryInterface
{
    /** The items of invoices issued in the period, in `$currency` (the organization's). */
    public function overview(BusinessPeriod $period, string $currency): PurchaseAnalysisData;

    /**
     * The price points of a product in `$currency` issued from `$from` to `$to` (each optional,
     * inclusive), oldest first. Each one's change is against the product's previous price point,
     * which may be before `$from`. Empty for a product without a unit or an excluded one.
     *
     * @return list<PricePoint>
     */
    public function priceHistory(Uuid $productId, string $currency, ?BusinessDate $from, ?BusinessDate $to): array;
}
