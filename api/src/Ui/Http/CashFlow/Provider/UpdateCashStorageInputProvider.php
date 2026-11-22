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
use Ui\Http\CashFlow\UpdateCashStorageInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the storage's current name and mode; API Platform then
 * applies the request body onto it. An employee is refused here (403) and a storage that is not the
 * organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdateCashStorageInput>
 */
final readonly class UpdateCashStorageInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateCashStorageInput
    {
        $storageId = $uriVariables['id'];
        \assert(\is_string($storageId));

        /** @var CashStorageView|null $view */
        $view = $this->queryBus->ask(new GetCashStorageQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $storageId,
            toManage: true,
        ));

        return UpdateCashStorageInput::fromView($view ?? throw StorageNotFoundException::withId($storageId));
    }
}
