<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Analysis\AnalysisAmounts;
use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Analysis\PurchaseAnalysisData;
use App\Purchasing\Domain\Repository\PurchaseAnalysisRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Answers with what a test sets and records what it was asked; the aggregation itself is tested against the database. */
final class InMemoryPurchaseAnalysisRepository implements PurchaseAnalysisRepositoryInterface
{
    public ?PurchaseAnalysisData $data = null;

    /** @var list<PricePoint> */
    public array $points = [];

    /** @var list<array{BusinessPeriod, string}> */
    public array $overviews = [];

    /** @var list<array{string, string, ?string, ?string}> */
    public array $histories = [];

    public function overview(BusinessPeriod $period, string $currency): PurchaseAnalysisData
    {
        $this->overviews[] = [$period, $currency];

        return $this->data ?? new PurchaseAnalysisData([], [], new AnalysisAmounts(0, 0), 0, 0, 0, 0, 0);
    }

    public function priceHistory(Uuid $productId, string $currency, ?BusinessDate $from, ?BusinessDate $to): array
    {
        $this->histories[] = [$productId->toRfc4122(), $currency, $from?->toString(), $to?->toString()];

        return $this->points;
    }
}
