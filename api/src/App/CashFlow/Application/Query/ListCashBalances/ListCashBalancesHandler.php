<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashBalances;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListCashBalancesHandler implements QueryHandlerInterface
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private DenominationRepositoryInterface $denominations,
        private CashMovementRepositoryInterface $movements,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<CashBalanceView>|null active storages first, each group by name
     *
     * @throws CashFlowAccessForbiddenException when the actor is an employee
     */
    public function __invoke(ListCashBalancesQuery $query): ?array
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        CashFlowAccess::assertCanReadLedger($actor->id, $actor->role);

        $currency = $actor->organization->currency;
        $offered = $this->denominations->findByCurrency($currency);

        $views = [];
        foreach ($this->storages->findAll(includeDeactivated: true) as $storage) {
            $balance = $this->movements->balanceOf($storage->getId());
            if (!$storage->isActive() && [] === $balance->pieces) {
                continue;
            }

            $denominations = [];
            foreach ($offered as $denomination) {
                $denominations[$denomination->getId()->toRfc4122()] = $denomination;
            }
            $denominations += $this->denominations->findByIds(array_values(array_diff(array_map(strval(...), array_keys($balance->pieces)), array_keys($denominations))));

            $lines = array_map(
                static function (Denomination $denomination) use ($balance): CashBalanceLineView {
                    $pieces = $balance->pieces[$denomination->getId()->toRfc4122()] ?? 0;

                    return new CashBalanceLineView(
                        $denomination->getId()->toRfc4122(),
                        $denomination->getCurrency(),
                        $denomination->getValue(),
                        $denomination->getKind()->value,
                        $denomination->isActive(),
                        $pieces,
                        $pieces * $denomination->getValue(),
                    );
                },
                array_values($denominations),
            );
            usort($lines, static fn (CashBalanceLineView $a, CashBalanceLineView $b): int => [$a->currency !== $currency, $a->currency, $a->value, $a->denominationId] <=> [$b->currency !== $currency, $b->currency, $b->value, $b->denominationId]);

            $views[] = new CashBalanceView(
                $storage->getId()->toRfc4122(),
                $storage->getName(),
                $storage->getMode()->value,
                $storage->isActive(),
                $currency,
                $lines,
                array_sum(array_map(static fn (CashBalanceLineView $line): int => $line->currency === $currency ? $line->amount : 0, $lines)),
                CashStorageMode::Counted === $storage->getMode(),
                $balance->countedAt,
            );
        }

        return $views;
    }
}
