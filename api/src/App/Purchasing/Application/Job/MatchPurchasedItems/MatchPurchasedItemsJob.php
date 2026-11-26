<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\MatchPurchasedItems;

use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Matches one organization's purchased items to its products (ADR 0042 §7). Queued when items are
 * recorded (items the model never saw, with the model), by `app:purchasing:match-items` (the history,
 * or again the unassigned ones) and after a person's confirmation (all matchable items, aliases only).
 * When its time budget runs out it queues itself again from the last item it handled (`$afterItemId`).
 *
 * `$askedBefore` (an ISO 8601 instant) limits it to the items the model has not been through since then: the
 * moment of the trigger that queued it, so a pass that runs twice asks each item once (ADR 0046 §3).
 * `$runId` is the id of the MatchingRun the job belongs to once one was recorded (ADR 0046 §6); a job that
 * continues another keeps it.
 */
final readonly class MatchPurchasedItemsJob implements OrganizationJobInterface
{
    /**
     * How long the pass after a catalog change waits, so that every product created or changed meanwhile is in
     * the catalog it sees and adds no pass of its own (ADR 0046 §2).
     */
    public const int SETTLE_DELAY_SECONDS = 5 * 60;

    public function __construct(
        public string $organizationId,
        /** A MatchScope value. */
        public string $scope,
        public bool $useInterpreter,
        public ?string $afterItemId = null,
        public ?string $askedBefore = null,
        public ?string $runId = null,
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

    /**
     * The pass over the unassigned items that follows a catalog change (ADR 0046 §2), with the model. Its key is its
     * own, so that "Match again" is never swallowed by one that is still waiting for its delay.
     */
    public static function afterCatalogChangeDeduplicationKey(string $organizationId): string
    {
        return \sprintf('purchasing-match-items:%s:after-catalog-change', $organizationId);
    }

    /** The pass of ADR 0046 §2: the unassigned items, with the model, that the model has not been through since `$trigger`. */
    public static function afterCatalogChange(string $organizationId, \DateTimeImmutable $trigger): self
    {
        return new self($organizationId, MatchScope::Unassigned->value, true, null, self::cutoffOf($trigger));
    }

    /** A trigger's moment as `$askedBefore` writes it: with the microseconds matching notes items by. */
    public static function cutoffOf(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d\TH:i:s.uP');
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
