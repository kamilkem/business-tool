<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\CashMovement;
use Symfony\Component\Validator\Constraints as Assert;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * Pieces added to or taken out of one storage (ADR 0033 §4). Whether the storage and the
 * denominations are the organization's, the moment not after now, and a deposit storage's balance
 * never below zero, the domain checks.
 */
final readonly class RecordCashAdjustmentInput
{
    /** @param list<CashAdjustmentLineInput> $lines */
    public function __construct(
        /** A storage of the organization (GET /cash-storages). */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $storageId,
        /** At least one line, each denomination once. */
        #[Assert\Count(min: 1)]
        #[Assert\Unique(normalizer: [CashMovementLines::class, 'denominationOf'], message: 'Each denomination may appear only once.')]
        #[Assert\Valid]
        public array $lines,
        /** E.g. "change from the bank", "bank run", "opening float". */
        #[Assert\Length(max: CashMovement::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $note = null,
        /** When the cash moved, with a time zone offset; now when left out. Never after now. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the cash moved, with a time zone offset; now when left out. Never after now.'])]
        #[IsoDateTime]
        public ?string $occurredAt = null,
    ) {
    }
}
