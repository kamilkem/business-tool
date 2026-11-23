<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;

/**
 * The expected opening of counted storages at a close (ADR 0033 §6): the value of each one's ledger
 * balance just before the close's position, in the close's currency. Pieces in another currency
 * (from before a currency change) have no value in it. Recording, correcting and recalculating a
 * close all compute them here.
 */
final readonly class ExpectedOpenings
{
    public function __construct(
        private CashLedgerInterface $ledger,
        private DenominationRepositoryInterface $denominations,
    ) {
    }

    /**
     * @param list<CashStorage> $storages the counted ones
     *
     * @return array<string, int> minor units by storage id
     */
    public function at(LedgerPosition $position, array $storages, string $currency): array
    {
        $openings = [];
        foreach ($storages as $storage) {
            $openings[$storage->getId()->toRfc4122()] = $this->valueOf($this->ledger->balanceOf($storage->getId(), $position)->pieces, $currency);
        }

        return $openings;
    }

    /**
     * The value of pieces in the currency; pieces of another currency count as nothing.
     *
     * @param array<array-key, int> $pieces by denomination id
     *
     * @return int minor units
     */
    public function valueOf(array $pieces, string $currency): int
    {
        $denominations = $this->denominations->findByIds(array_map(strval(...), array_keys($pieces)));
        $value = 0;
        foreach ($pieces as $denominationId => $count) {
            $denomination = $denominations[(string) $denominationId] ?? throw new \LogicException('Denomination expected.');
            if ($denomination->getCurrency() === $currency) {
                $value += $count * $denomination->getValue();
            }
        }

        return $value;
    }
}
