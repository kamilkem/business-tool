<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\AddCashStorage;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidStorageNameException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AddCashStorageHandler implements CommandHandlerInterface
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
     * @throws InvalidStorageNameException      when the name is blank or too long
     * @throws StorageNameTakenException        when the organization already has a storage with the name
     * @throws \ValueError                      when the mode is neither `counted` nor `deposit` (the input validates it)
     */
    public function __invoke(AddCashStorageCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $storage = CashStorage::create(
            Uuid::fromString($command->storageId),
            $command->name,
            CashStorageMode::from($command->mode),
            $this->storages->findByName(trim($command->name)),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->storages->add($storage);
    }
}
