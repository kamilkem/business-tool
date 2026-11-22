<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteCashMovement;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\MovementNotFoundException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteCashMovementHandler implements CommandHandlerInterface
{
    public function __construct(
        private CashMovementRepositoryInterface $movements,
        private CashLedgerWriter $ledger,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws MovementNotFoundException        when the movement is not one of the organization's
     * @throws StorageBalanceNegativeException  when a deposit storage would go below zero without it
     */
    public function __invoke(DeleteCashMovementCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $movement = Uuid::isValid($command->movementId) ? $this->movements->findById(Uuid::fromString($command->movementId)) : null;
        $movement ??= throw MovementNotFoundException::withId($command->movementId);
        $movement->assertDeletable($actor->id, $actor->role);

        $this->ledger->delete($movement, $command->organizationId, $actor->id->toRfc4122());
    }
}
