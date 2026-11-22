<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordDayClose;

use App\Shared\Application\Command\CommandInterface;

/**
 * Any active member closes a business date (ADR 0033 §6): one entry per active storage (a full count
 * of a counted one, the pieces put into a deposit one), one amount per active non-cash payment
 * method and one active register. The date follows the organization's DayCloseSettings; null takes
 * the proposed one.
 */
final readonly class RecordDayCloseCommand implements CommandInterface
{
    /**
     * @param list<array{storageId: string, lines: list<array{denominationId: string, pieces: int}>}> $storages
     * @param list<array{paymentMethodId: string, amount: int}>                                       $payments minor units
     * @param ?string                                                                                 $date     Y-m-d in the organization's time zone
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
        public string $registerId,
        public array $storages,
        public array $payments,
        public ?string $date = null,
    ) {
    }
}
