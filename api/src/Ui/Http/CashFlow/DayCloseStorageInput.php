<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One storage of a close: for a counted storage, the pieces of each denomination that stay in it
 * (an active denomination left out counts as zero); for a deposit storage, the pieces put into it
 * (possibly none). Properties, not constructor parameters, as for the line inputs.
 */
final class DayCloseStorageInput
{
    /** An active storage of the organization (GET /day-close-form). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $storageId;

    /** @var list<CashCountLineInput> each denomination once */
    #[Assert\NotNull]
    #[Assert\Unique(normalizer: [CashMovementLines::class, 'denominationOf'], message: 'Each denomination may appear only once.')]
    #[Assert\Valid]
    public array $lines;

    /** @param list<CashCountLineInput> $lines */
    public static function of(string $storageId, array $lines): self
    {
        $input = new self();
        $input->storageId = $storageId;
        $input->lines = $lines;

        return $input;
    }

    /** For Assert\Unique: storages are the same when their ids are. */
    public static function storageOf(mixed $storage): mixed
    {
        return $storage instanceof self && isset($storage->storageId) ? strtolower($storage->storageId) : $storage;
    }
}
