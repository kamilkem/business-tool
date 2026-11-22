<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\AddDenomination;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Exception\InvalidDenominationValueException;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The currency is the organization's, never the client's. */
final readonly class AddDenominationHandler implements CommandHandlerInterface
{
    public function __construct(
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException     when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException  when the actor is not an owner or manager
     * @throws InvalidDenominationValueException when the value is not positive or too large
     * @throws DenominationValueTakenException   when the organization already has the value in its currency
     * @throws \ValueError                       when the kind is neither `coin` nor `note` (the input validates it)
     */
    public function __invoke(AddDenominationCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);
        $currency = $actor->organization->currency;

        $denomination = Denomination::create(
            Uuid::fromString($command->denominationId),
            $currency,
            $command->value,
            DenominationKind::from($command->kind),
            $this->denominations->findByValue($currency, $command->value),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->denominations->add($denomination);
    }
}
