<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeleteRegister;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RegisterInUseException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteRegisterHandler implements CommandHandlerInterface
{
    public function __construct(
        private RegisterRepositoryInterface $registers,
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterNotFoundException          when the register is not one of the organization's
     * @throws RegisterInUseException             when a revenue entry refers to the register
     */
    public function __invoke(DeleteRegisterCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $register = Uuid::isValid($command->registerId) ? $this->registers->findById(Uuid::fromString($command->registerId)) : null;
        $register ??= throw RegisterNotFoundException::withId($command->registerId);

        $register->assertDeletable($this->entries->usesRegister($register->getId()), $actor->id, $actor->role);

        // The foreign key of the entries catches an entry recorded in the meantime (remove()).
        $this->registers->remove($register);
    }
}
