<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ChangeCashStorage;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidStorageNameException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeCashStorageHandler implements CommandHandlerInterface
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
     * @throws InvalidStorageNameException      when the name is blank or too long
     * @throws StorageNameTakenException        when another storage of the organization has the name
     * @throws \ValueError                      when the mode is neither `counted` nor `deposit` (the input validates it)
     */
    public function __invoke(ChangeCashStorageCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $storage = Uuid::isValid($command->storageId) ? $this->storages->findById(Uuid::fromString($command->storageId)) : null;
        $storage ??= throw StorageNotFoundException::withId($command->storageId);

        $storage->rename($command->name, $this->storages->findByName(trim($command->name)), $actor->id, $actor->role);
        $storage->changeMode(CashStorageMode::from($command->mode), $actor->id, $actor->role);
        $this->storages->save($storage);
    }
}
