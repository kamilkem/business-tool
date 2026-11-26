<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\BackfillProductAliases;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Teaches one organization's aliases from the items its people confirmed before aliases were learned (ADR
 * 0045 §3). Queued by `app:purchasing:backfill-aliases` and, when its time budget runs out, by itself with
 * the last item it handled (`$afterItemId`), so each run passes every confirmed item once.
 */
final readonly class BackfillProductAliasesJob implements OrganizationJobInterface
{
    public function __construct(
        public string $organizationId,
        public ?string $afterItemId = null,
    ) {
    }

    /**
     * One per organization and position: a job holds its key until it was handled, so the one it queues to
     * continue needs its own key, or it would be dropped (ADR 0025 §5).
     */
    public static function deduplicationKey(string $organizationId, ?string $afterItemId = null): string
    {
        return 'purchasing-backfill-aliases:'.$organizationId.(null === $afterItemId ? '' : ':'.$afterItemId);
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
