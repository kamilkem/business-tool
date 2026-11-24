<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\ChangeKsefSyncStartDate;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\InvalidSyncStartDateException;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Psr\Clock\ClockInterface;

final readonly class ChangeKsefSyncStartDateHandler implements CommandHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws KsefConnectionForbiddenException when the actor is an employee
     * @throws KsefConnectionNotFoundException  when the organization has no KSeF connection
     * @throws InvalidBusinessDateException     when the date is not a Y-m-d date
     * @throws InvalidSyncStartDateException    when the date is after today or before KSeF API 2.0
     */
    public function __invoke(ChangeKsefSyncStartDateCommand $command): void
    {
        $actor = KsefActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        KsefAccess::assertCanUse($actor->id, $actor->role);
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();

        $connection->changeSyncStartDate(
            null === $command->syncStartDate ? null : BusinessDate::fromString($command->syncStartDate),
            $actor->id,
            $actor->role,
            new \DateTimeZone($actor->timeZone),
            $this->clock->now(),
        );
        $this->connections->save($connection);
    }
}
