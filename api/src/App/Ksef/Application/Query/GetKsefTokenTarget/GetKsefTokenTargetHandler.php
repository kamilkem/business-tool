<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefTokenTarget;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetKsefTokenTargetHandler implements QueryHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     * @throws KsefNotAvailableException        when saving and the organization is not in Poland or has no valid NIP
     */
    public function __invoke(GetKsefTokenTargetQuery $query): ?KsefTokenTargetView
    {
        $actor = KsefActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        KsefAccess::assertCanManageToken($actor->id, $actor->role);
        if ($query->toSave) {
            KsefAccess::nipFor($actor->countryCode, $actor->taxId);
        }

        $connection = $this->connections->findCurrent();

        return new KsefTokenTargetView($connection?->getId()->toRfc4122(), $connection?->getSecretId()?->toRfc4122());
    }
}
