<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * What the person closing the day enters for one storage (ADR 0033 §6): for a counted storage, the
 * pieces of each denomination that stay in it; for a deposit storage, the pieces put into it.
 */
final readonly class StorageCount
{
    /** @param list<MovementPieces> $pieces */
    public function __construct(
        public CashStorage $storage,
        public array $pieces,
    ) {
    }
}
