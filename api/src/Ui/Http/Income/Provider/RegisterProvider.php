<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetRegister\GetRegisterQuery;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\RegisterResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<RegisterResource> */
final readonly class RegisterProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RegisterResource
    {
        $registerId = $uriVariables['id'];
        \assert(\is_string($registerId));

        /** @var RegisterView|null $view */
        $view = $this->queryBus->ask(new GetRegisterQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $registerId,
        ));

        return RegisterResource::fromView($view ?? throw RegisterNotFoundException::withId($registerId));
    }
}
