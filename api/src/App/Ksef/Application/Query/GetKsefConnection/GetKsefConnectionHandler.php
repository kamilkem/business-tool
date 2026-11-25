<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefConnection;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Model\Nip;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Secret\Application\Query\GetOrganizationSecret\GetOrganizationSecretQuery;
use App\Secret\Application\Query\GetOrganizationSecret\OrganizationSecretView;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Psr\Clock\ClockInterface;

final readonly class GetKsefConnectionHandler implements QueryHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /** @throws KsefConnectionForbiddenException when the actor is an employee */
    public function __invoke(GetKsefConnectionQuery $query): ?KsefConnectionView
    {
        $actor = KsefActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        KsefAccess::assertCanUse($actor->id, $actor->role);

        $connection = $this->connections->findCurrent();
        if (null === $connection) {
            return null;
        }

        $secretId = $connection->getSecretId();
        /** @var OrganizationSecretView|null $secret */
        $secret = null === $secretId ? null : $this->queryBus->ask(new GetOrganizationSecretQuery($query->organizationId, $secretId->toRfc4122()));

        return KsefConnectionView::fromConnection($connection, $secret?->setAt, Nip::tryFromTaxId($actor->taxId)?->value, new \DateTimeZone($actor->timeZone), $this->clock->now());
    }
}
