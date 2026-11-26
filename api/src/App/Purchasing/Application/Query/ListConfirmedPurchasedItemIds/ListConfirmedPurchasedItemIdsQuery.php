<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListConfirmedPurchasedItemIds;

use App\Shared\Application\Query\QueryInterface;

/**
 * The ids of the current organization's confirmed items that have a product, newest invoice first, after
 * `$afterItemId` in that order: for the job that learns aliases from them (ADR 0045 §3), which has no actor.
 */
final readonly class ListConfirmedPurchasedItemIdsQuery implements QueryInterface
{
    public function __construct(
        public ?string $afterItemId,
        public int $limit,
    ) {
    }
}
