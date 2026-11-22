<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteCashStorage;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteCashStorageHandler implements CommandHandlerInterface
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws StorageNotFoundException         when the storage is not one of the organization's
     * @throws StorageInUseException            when a close or movement refers to it
     */
    public function __invoke(DeleteCashStorageCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $storage = Uuid::isValid($command->storageId) ? $this->storages->findById(Uuid::fromString($command->storageId)) : null;
        $storage ??= throw StorageNotFoundException::withId($command->storageId);

        $storage->assertDeletable($this->storages->isUsed($storage->getId()), $actor->id, $actor->role);

        // The foreign keys of the ledger catch a use recorded in the meantime (remove()).
        $this->storages->remove($storage);
    }
}
