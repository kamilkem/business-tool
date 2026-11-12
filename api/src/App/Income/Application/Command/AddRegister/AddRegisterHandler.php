<?php

declare(strict_types=1);

namespace App\Income\Application\Command\AddRegister;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidRegisterNameException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AddRegisterHandler implements CommandHandlerInterface
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
     * @throws InvalidRegisterNameException       when the name is blank or too long
     * @throws RegisterNameTakenException         when a register of the organization, active or not, has the name
     */
    public function __invoke(AddRegisterCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $register = Register::create(
            Uuid::fromString($command->registerId),
            $command->name,
            $this->registers->findByName(trim($command->name)),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->registers->add($register);
    }
}
