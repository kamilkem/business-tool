<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeactivateDenomination;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeactivateDenominationHandler implements CommandHandlerInterface
{
    public function __construct(
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws DenominationNotFoundException    when the denomination is not one of the organization's
     */
    public function __invoke(DeactivateDenominationCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);

        $denomination = Uuid::isValid($command->denominationId) ? $this->denominations->findById(Uuid::fromString($command->denominationId)) : null;
        $denomination ??= throw DenominationNotFoundException::withId($command->denominationId);

        $denomination->deactivate($actor->id, $actor->role, $this->clock->now());
        $this->denominations->save($denomination);
    }
}
