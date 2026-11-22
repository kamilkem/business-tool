<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Application\Query\GetCashMovement\GetCashMovementQuery;
use App\CashFlow\Domain\Exception\MovementNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashMovementResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<CashMovementResource> */
final readonly class CashMovementProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CashMovementResource
    {
        $movementId = $uriVariables['id'];
        \assert(\is_string($movementId));

        /** @var CashMovementView|null $view */
        $view = $this->queryBus->ask(new GetCashMovementQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $movementId,
        ));

        return CashMovementResource::fromView($view ?? throw MovementNotFoundException::withId($movementId));
    }
}
