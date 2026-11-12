<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ChangeRevenueEntry;

use App\Income\Application\IncomeActor;
use App\Income\Application\RevenueLines;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The VAT rates are those of the organization's current country (ADR 0022 §6). */
final readonly class ChangeRevenueEntryHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RevenueEntryNotFoundException      when the entry is not one of the organization's
     * @throws InvalidPaymentLinesException       when the payment lines break a rule
     * @throws InvalidVatLinesException           when the VAT lines break a rule
     */
    public function __invoke(ChangeRevenueEntryCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $entry = Uuid::isValid($command->entryId) ? $this->entries->findById(Uuid::fromString($command->entryId)) : null;
        $entry ??= throw RevenueEntryNotFoundException::withId($command->entryId);

        $entry->change(
            RevenueLines::payments($command->payments),
            RevenueLines::vat($command->vatLines),
            $command->note,
            $actor->organization->countryCode,
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->entries->save($entry);
    }
}
