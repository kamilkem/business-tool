<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRegisters;

use App\Income\Application\IncomeActor;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListRegistersHandler implements QueryHandlerInterface
{
    public function __construct(
        private RegisterRepositoryInterface $registers,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<RegisterView>|null active registers first, then by name
     *
     * @throws IncomeManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListRegistersQuery $query): ?array
    {
        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        return array_map(
            RegisterView::fromRegister(...),
            $this->registers->findAll($query->includeDeactivated),
        );
    }
}
