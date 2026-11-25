<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * Which purchased items a listing selects; every criterion that is set must hold.
 */
final readonly class PurchasedItemFilter
{
    /**
     * @param list<PurchasedItemStatus> $statuses     any of these; none: any status
     * @param ?Uuid                     $productId    only the items linked to this product
     * @param ?BusinessDate             $from         the invoice's issue date, inclusive, or later
     * @param ?BusinessDate             $to           the invoice's issue date, inclusive, or earlier
     * @param ?string                   $nameContains a text the item's name contains, case-insensitively
     */
    public function __construct(
        public array $statuses = [],
        public ?Uuid $productId = null,
        public ?BusinessDate $from = null,
        public ?BusinessDate $to = null,
        public ?string $nameContains = null,
    ) {
    }
}
