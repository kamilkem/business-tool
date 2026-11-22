<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteDenomination;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteDenominationHandler implements CommandHandlerInterface
{
    public function __construct(
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws DenominationNotFoundException    when the denomination is not one of the organization's
     * @throws DenominationInUseException       when a close or movement uses it
     */
    public function __invoke(DeleteDenominationCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $denomination = Uuid::isValid($command->denominationId) ? $this->denominations->findById(Uuid::fromString($command->denominationId)) : null;
        $denomination ??= throw DenominationNotFoundException::withId($command->denominationId);

        $denomination->assertDeletable($this->denominations->isUsed($denomination->getId()), $actor->id, $actor->role);

        // The foreign keys of the ledger catch a use recorded in the meantime (remove()).
        $this->denominations->remove($denomination);
    }
}
