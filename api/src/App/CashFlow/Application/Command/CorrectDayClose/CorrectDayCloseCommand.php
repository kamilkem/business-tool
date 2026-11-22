<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\CorrectDayClose;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager corrects an end-of-day close (ADR 0033 §7): one entry per storage the close
 * covered (a full count of a counted one, the pieces put into a deposit one), one amount per non-cash
 * payment method it took, and its register (its own or an active one). The date never changes.
 */
final readonly class CorrectDayCloseCommand implements CommandInterface
{
    /**
     * @param list<array{storageId: string, lines: list<array{denominationId: string, pieces: int}>}> $storages
     * @param list<array{paymentMethodId: string, amount: int}>                                       $payments minor units
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
        public string $registerId,
        public array $storages,
        public array $payments,
    ) {
    }
}
