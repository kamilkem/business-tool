<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeactivateRegister;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeactivateRegisterHandler implements CommandHandlerInterface
{
    public function __construct(
        private RegisterRepositoryInterface $registers,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterNotFoundException          when the register is not one of the organization's
     */
    public function __invoke(DeactivateRegisterCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $register = Uuid::isValid($command->registerId) ? $this->registers->findById(Uuid::fromString($command->registerId)) : null;
        $register ??= throw RegisterNotFoundException::withId($command->registerId);

        $register->deactivate($actor->id, $actor->role, $this->clock->now());

        $this->registers->save($register);
    }
}
