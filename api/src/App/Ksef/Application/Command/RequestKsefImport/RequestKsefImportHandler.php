<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RequestKsefImport;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Exception\KsefImportTooSoonException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefTokenNotSetException;
use App\Ksef\Domain\Exception\KsefTokenRejectedException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;

final readonly class RequestKsefImportHandler implements CommandHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws KsefConnectionForbiddenException when the actor is an employee
     * @throws KsefConnectionNotFoundException  when the organization has no KSeF connection
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     * @throws KsefTokenNotSetException         when the token was removed
     * @throws KsefTokenRejectedException       when KSeF rejected the token
     * @throws KsefImportTooSoonException       within 15 minutes of the last request, or before KSeF's Retry-After
     */
    public function __invoke(RequestKsefImportCommand $command): void
    {
        $actor = KsefActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        KsefAccess::assertCanUse($actor->id, $actor->role);
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();

        $connection->requestImport($actor->countryCode, $actor->taxId, $actor->id, $actor->role, $this->clock->now());
        $this->connections->save($connection);

        $this->jobBus->dispatch(new ImportKsefInvoicesJob($command->organizationId), ImportKsefInvoicesJob::deduplicationKey($command->organizationId));
    }
}
