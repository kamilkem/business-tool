<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RecordRevenueEntry;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager records a register's daily report (ADR 0022). Amounts are integer minor units
 * of the organization's currency (ADR 0010); the business date is `YYYY-MM-DD`. The organization must
 * be the current tenant (TenantContextInterface): the entry is stored in it.
 */
final readonly class RecordRevenueEntryCommand implements CommandInterface
{
    /**
     * @param list<array{method: string, amount: int}>           $payments
     * @param list<array{rate: string, gross: int, vat: int}> $vatLines
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $entryId,
        public string $registerId,
        public string $businessDate,
        public array $payments,
        public array $vatLines,
        public ?string $note,
    ) {
    }
}
