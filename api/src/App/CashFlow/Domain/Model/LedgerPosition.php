<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * A place in the ledger: ledger order is (occurredAt, id), so two records at the same moment keep
 * a stable order (ADR 0033 §4). The id is a record's UUID v7.
 */
final readonly class LedgerPosition
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public Uuid $id,
    ) {
    }

    public function isBefore(self $other): bool
    {
        return [$this->occurredAt, $this->id->toRfc4122()] < [$other->occurredAt, $other->id->toRfc4122()];
    }
}
