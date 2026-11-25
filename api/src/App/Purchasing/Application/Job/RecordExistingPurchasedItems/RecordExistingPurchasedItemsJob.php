<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Job\RecordExistingPurchasedItems;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Copies the items of one organization's invoices that were stored before Purchasing existed into
 * purchased items (#240, ADR 0041 §2). Queued by `app:purchasing:record-existing-items` and, when its
 * time budget runs out, by itself with the last invoice it handled (`$afterInvoiceId`), so each run
 * passes every invoice with items once.
 */
final readonly class RecordExistingPurchasedItemsJob implements OrganizationJobInterface
{
    public function __construct(
        public string $organizationId,
        public ?string $afterInvoiceId = null,
    ) {
    }

    /**
     * One per organization and position: a job holds its key until it was handled, so the one it
     * queues to continue from `$afterInvoiceId` needs its own key, or it would be dropped (ADR 0025 §5).
     */
    public static function deduplicationKey(string $organizationId, ?string $afterInvoiceId = null): string
    {
        return 'purchasing-record-existing-items:'.$organizationId.(null === $afterInvoiceId ? '' : ':'.$afterInvoiceId);
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
