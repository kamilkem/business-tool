<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Application\Query\GetCashMovement\CashMovementLineView;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashMovementKind;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Builds the views of movements: who made each one (through the Organization module's members,
 * deactivated ones included, ADR 0014) and an audit count's discrepancy, derived from the ledger
 * (ADR 0033 §4).
 */
final readonly class CashMovementViews
{
    public function __construct(
        private CashLedgerInterface $ledger,
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @param list<CashMovement> $movements
     *
     * @return list<CashMovementView> in the same order
     */
    public function of(array $movements, string $organizationId, string $actorId): array
    {
        if ([] === $movements) {
            return [];
        }

        /** @var list<MemberView>|null $all */
        $all = $this->queryBus->ask(new ListMembersQuery($organizationId, $actorId, includeDeactivated: true));
        $members = [];
        foreach ($all ?? [] as $member) {
            $members[$member->userId] = $member;
        }

        return array_map(fn (CashMovement $movement): CashMovementView => $this->view($movement, $members), $movements);
    }

    /** @param array<string, MemberView> $members by user id */
    private function view(CashMovement $movement, array $members): CashMovementView
    {
        $storages = $movement->storages();
        $isTransfer = CashMovementKind::Transfer === $movement->getKind();
        // A transfer's lines are shown once, as the pieces that enter the second storage.
        $shown = $isTransfer ? $storages[1] : $storages[0];

        $lines = [];
        $currency = null;
        foreach ($movement->getLines() as $line) {
            if (!$line->getStorage()->getId()->equals($shown->getId())) {
                continue;
            }
            $denomination = $line->getDenomination();
            $currency ??= $denomination->getCurrency();
            $lines[] = new CashMovementLineView(
                $denomination->getId()->toRfc4122(),
                $denomination->getValue(),
                $denomination->getKind()->value,
                $line->getPieces(),
                $line->getPieces() * $denomination->getValue(),
            );
        }
        usort($lines, static fn (CashMovementLineView $a, CashMovementLineView $b): int => [$a->value, $a->denominationId] <=> [$b->value, $b->denominationId]);
        $total = array_sum(array_map(static fn (CashMovementLineView $line): int => $line->amount, $lines));

        $createdBy = $movement->getCreatedBy()->toRfc4122();
        $member = $members[$createdBy]
            // Memberships are deactivated, never deleted (ADR 0014), so every movement has its member.
            ?? throw new \LogicException(\sprintf('Cash movement "%s" was made by someone who is not a member.', $movement->getId()->toRfc4122()));

        return new CashMovementView(
            $movement->getId()->toRfc4122(),
            $movement->getKind()->value,
            $movement->getOccurredAt(),
            $storages[0]->getId()->toRfc4122(),
            $storages[0]->getName(),
            $isTransfer ? $storages[1]->getId()->toRfc4122() : null,
            $isTransfer ? $storages[1]->getName() : null,
            $currency ?? throw new \LogicException('A movement has lines.'),
            $lines,
            $total,
            CashMovementKind::Count === $movement->getKind() ? $total - $this->valueBefore($movement) : null,
            $movement->getNote(),
            $createdBy,
            $member->firstName,
            $member->lastName,
            $movement->getCreatedAt(),
        );
    }

    /** The value of the counted storage's balance just before the count, in minor units. */
    private function valueBefore(CashMovement $count): int
    {
        $storage = $count->storages()[0];
        $balance = $this->ledger->balanceOf($storage->getId(), $count->position())->pieces;
        $denominations = $this->denominations->findByIds(array_map(strval(...), array_keys($balance)));

        $value = 0;
        foreach ($balance as $denominationId => $pieces) {
            $value += $pieces * ($denominations[(string) $denominationId] ?? throw new \LogicException('Denomination expected.'))->getValue();
        }

        return $value;
    }
}
