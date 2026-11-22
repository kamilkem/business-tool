<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\CashMovement;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * The same pieces out of one storage and into another (ADR 0033 §4). Whether the storages and the
 * denominations are the organization's, the moment not after now, and a deposit storage's balance
 * never below zero, the domain checks.
 */
final readonly class RecordCashTransferInput
{
    /** @param list<CashTransferLineInput> $lines */
    public function __construct(
        /** The storage the cash leaves (GET /cash-storages). */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $fromStorageId,
        /** The storage the cash enters: another one, active. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $toStorageId,
        /** At least one line, each denomination once. */
        #[Assert\Count(min: 1)]
        #[Assert\Unique(normalizer: [CashMovementLines::class, 'denominationOf'], message: 'Each denomination may appear only once.')]
        #[Assert\Valid]
        public array $lines,
        #[Assert\Length(max: CashMovement::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $note = null,
        /** When the cash moved, with a time zone offset; now when left out. Never after now. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the cash moved, with a time zone offset; now when left out. Never after now.'])]
        #[IsoDateTime]
        public ?string $occurredAt = null,
    ) {
    }

    #[Assert\Callback]
    public function validateStorages(ExecutionContextInterface $context): void
    {
        if ('' !== $this->fromStorageId && strtolower($this->fromStorageId) === strtolower($this->toStorageId)) {
            $context->buildViolation('The cash must go into another storage.')->atPath('toStorageId')->addViolation();
        }
    }
}
