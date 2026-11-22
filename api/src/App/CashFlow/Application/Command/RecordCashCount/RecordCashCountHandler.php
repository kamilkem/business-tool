<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordCashCount;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidMovementException;
use App\CashFlow\Domain\Exception\MovementInTheFutureException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Exception\AuditCountNeedsDepositStorageException;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RecordCashCountHandler implements CommandHandlerInterface
{
    public function __construct(
        private CashLedgerWriter $ledger,
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException       when the actor is not an owner or manager
     * @throws StorageNotFoundException               when the storage is not one of the organization's
     * @throws AuditCountNeedsDepositStorageException when the storage is counted at each close
     * @throws StorageDeactivatedException            when the storage is deactivated
     * @throws DenominationNotFoundException          when a denomination is not one of the organization's
     * @throws InvalidMovementException               when the lines or the note break a rule, or no denomination is active
     * @throws DenominationNotOfferedException        when a denomination is in another currency or deactivated
     * @throws MovementInTheFutureException           when it is set after now
     * @throws StorageBalanceNegativeException        when the storage would go below zero after it
     */
    public function __invoke(RecordCashCountCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $movement = CashMovement::count(
            Uuid::fromString($command->movementId),
            $this->ledger->storage($command->storageId),
            $this->ledger->pieces($command->lines),
            $this->denominations->findByCurrency($actor->organization->currency),
            $actor->organization->currency,
            $command->note,
            null === $command->occurredAt ? null : new \DateTimeImmutable($command->occurredAt),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->ledger->record($movement, $command->organizationId, $actor->id->toRfc4122());
    }
}
