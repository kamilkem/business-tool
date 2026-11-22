<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * What one record of the ledger does to one storage (ADR 0033 §4): a count sets its pieces of every
 * denomination (a denomination it does not list is zero), any other record adds signed pieces.
 * Close counts and close deposits (#185) are entries too.
 */
final readonly class LedgerEntry
{
    /**
     * @param array<string, int> $pieces by denomination id: absolute for a count, signed otherwise
     */
    private function __construct(
        public LedgerPosition $position,
        public bool $isCount,
        public array $pieces,
    ) {
    }

    /** @param array<string, int> $pieces absolute pieces by denomination id */
    public static function count(LedgerPosition $position, array $pieces): self
    {
        return new self($position, true, $pieces);
    }

    /** @param array<string, int> $pieces signed pieces by denomination id */
    public static function delta(LedgerPosition $position, array $pieces): self
    {
        return new self($position, false, $pieces);
    }

    /**
     * The storage's pieces after this entry.
     *
     * @param array<string, int> $before by denomination id
     *
     * @return array<string, int> by denomination id, without zeros
     */
    public function applyTo(array $before): array
    {
        if ($this->isCount) {
            return array_filter($this->pieces, static fn (int $pieces): bool => 0 !== $pieces);
        }

        $after = $before;
        foreach ($this->pieces as $denominationId => $pieces) {
            $after[$denominationId] = ($after[$denominationId] ?? 0) + $pieces;
        }

        return array_filter($after, static fn (int $pieces): bool => 0 !== $pieces);
    }
}
