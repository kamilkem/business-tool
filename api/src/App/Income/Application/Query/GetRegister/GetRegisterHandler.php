<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRegister;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetRegisterHandler implements QueryHandlerInterface
{
    public function __construct(
        private RegisterRepositoryInterface $registers,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws IncomeManagementForbiddenException when the actor is an employee */
    public function __invoke(GetRegisterQuery $query): ?RegisterView
    {
        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $register = Uuid::isValid($query->registerId) ? $this->registers->findById(Uuid::fromString($query->registerId)) : null;

        return null === $register ? null : RegisterView::fromRegister($register);
    }
}
