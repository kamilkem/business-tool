<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeactivateCashStorage;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeactivateCashStorageHandler implements CommandHandlerInterface
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws StorageNotFoundException         when the storage is not one of the organization's
     */
    public function __invoke(DeactivateCashStorageCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $storage = Uuid::isValid($command->storageId) ? $this->storages->findById(Uuid::fromString($command->storageId)) : null;
        $storage ??= throw StorageNotFoundException::withId($command->storageId);

        $storage->deactivate($actor->id, $actor->role, $this->clock->now());
        $this->storages->save($storage);
    }
}
