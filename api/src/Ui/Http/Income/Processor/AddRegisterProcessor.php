<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\AddRegister\AddRegisterCommand;
use App\Income\Application\Query\GetRegister\GetRegisterQuery;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Income\AddRegisterInput;
use Ui\Http\Income\RegisterResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds a register to the organization selected by the X-Organization-Id header
 * (ADR 0022 §2).
 *
 * @implements ProcessorInterface<AddRegisterInput, RegisterResource>
 */
final readonly class AddRegisterProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddRegisterInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RegisterResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $registerId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new AddRegisterCommand($organizationId, $actorId, $registerId, $data->name));

        /** @var RegisterView $view */
        $view = $this->queryBus->ask(new GetRegisterQuery($organizationId, $actorId, $registerId));

        return RegisterResource::fromView($view);
    }
}
