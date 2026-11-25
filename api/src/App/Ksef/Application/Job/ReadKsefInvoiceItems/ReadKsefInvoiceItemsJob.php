<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ReadKsefInvoiceItems;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Reads the items of one organization's KSeF invoices imported before items were kept (#230, ADR
 * 0040) from their stored FA(3) XML. Queued by `app:ksef:read-invoice-items` and, when its time
 * budget runs out, by itself with the last invoice it handled (`$afterInvoiceId`), so each run passes
 * every invoice once, also those whose XML lists no items (advance invoices).
 */
final readonly class ReadKsefInvoiceItemsJob implements OrganizationJobInterface
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
        return 'ksef-read-invoice-items:'.$organizationId.(null === $afterInvoiceId ? '' : ':'.$afterInvoiceId);
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
