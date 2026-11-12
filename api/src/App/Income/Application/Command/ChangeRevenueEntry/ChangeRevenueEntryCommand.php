<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ChangeRevenueEntry;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager corrects an entry's payment lines, VAT lines and note: the whole new state of
 * each (ADR 0022 §8). The register and the business date never change. Amounts are integer minor
 * units of the entry's currency (ADR 0010).
 */
final readonly class ChangeRevenueEntryCommand implements CommandInterface
{
    /**
     * @param list<array{method: string, amount: int}>           $payments
     * @param list<array{rate: string, gross: int, vat: int}> $vatLines an empty list removes the breakdown
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $entryId,
        public array $payments,
        public array $vatLines,
        public ?string $note,
    ) {
    }
}
