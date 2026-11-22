<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/** Pieces of one denomination entered for a movement, before CashMovement checks them. */
final readonly class MovementPieces
{
    public function __construct(
        public Denomination $denomination,
        public int $pieces,
    ) {
    }
}
