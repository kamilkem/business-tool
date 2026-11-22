<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageQuery;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashStorageResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<CashStorageResource> */
final readonly class CashStorageProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CashStorageResource
    {
        $storageId = $uriVariables['id'];
        \assert(\is_string($storageId));

        /** @var CashStorageView|null $view */
        $view = $this->queryBus->ask(new GetCashStorageQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $storageId,
        ));

        return CashStorageResource::fromView($view ?? throw StorageNotFoundException::withId($storageId));
    }
}
