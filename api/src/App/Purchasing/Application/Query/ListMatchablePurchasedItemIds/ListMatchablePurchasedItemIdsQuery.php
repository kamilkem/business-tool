<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListMatchablePurchasedItemIds;

use App\Shared\Application\Query\QueryInterface;

/**
 * The ids of the items matching may change in the current organization (ADR 0042 §7), by id, after
 * `$afterItemId`: for the matching job, which has no actor. `$scope` is a MatchScope value.
 */
final readonly class ListMatchablePurchasedItemIdsQuery implements QueryInterface
{
    public function __construct(
        public string $scope,
        public ?string $afterItemId,
        public int $limit,
    ) {
    }
}
