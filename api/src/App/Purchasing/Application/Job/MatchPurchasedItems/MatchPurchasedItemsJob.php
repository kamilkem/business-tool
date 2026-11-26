<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\MatchPurchasedItems;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Matches one organization's purchased items to its products (ADR 0042 §7). Queued when items are
 * recorded (items the model never saw, with the model), by `app:purchasing:match-items` (the history,
 * or again the unassigned ones) and after a person's confirmation (all matchable items, aliases only).
 * When its time budget runs out it queues itself again from the last item it handled (`$afterItemId`).
 */
final readonly class MatchPurchasedItemsJob implements OrganizationJobInterface
{
    public function __construct(
        public string $organizationId,
        /** A MatchScope value. */
        public string $scope,
        public bool $useInterpreter,
        public ?string $afterItemId = null,
    ) {
    }

    /**
     * One per organization, scope and position: a job holds its key until it was handled, so the one it
     * queues to continue needs its own key, or it would be dropped (ADR 0025 §5).
     */
    public static function deduplicationKey(string $organizationId, string $scope, bool $useInterpreter, ?string $afterItemId = null): string
    {
        return \sprintf('purchasing-match-items:%s:%s:%s%s', $organizationId, $scope, $useInterpreter ? 'model' : 'aliases', null === $afterItemId ? '' : ':'.$afterItemId);
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
