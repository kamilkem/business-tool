<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\CashMovement;
use Symfony\Component\Validator\Constraints as Assert;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * An audit count of a deposit storage (ADR 0033 §4): the pieces it holds, which replace its balance.
 * Whether the storage is a deposit one and the denominations are the organization's and active, and
 * the moment not after now, the domain checks.
 */
final readonly class RecordCashCountInput
{
    /** @param list<CashCountLineInput> $lines */
    public function __construct(
        /** A deposit storage of the organization (GET /cash-storages). */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $storageId,
        /** Each denomination once; an active denomination left out counts as zero, so an empty list is an empty storage. */
        #[Assert\NotNull]
        #[Assert\Unique(normalizer: [CashMovementLines::class, 'denominationOf'], message: 'Each denomination may appear only once.')]
        #[Assert\Valid]
        public array $lines,
        #[Assert\Length(max: CashMovement::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $note = null,
        /** When the storage was counted, with a time zone offset; now when left out. Never after now. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the storage was counted, with a time zone offset; now when left out. Never after now.'])]
        #[IsoDateTime]
        public ?string $occurredAt = null,
    ) {
    }
}
