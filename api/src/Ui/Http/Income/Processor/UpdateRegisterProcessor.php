<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\RenameRegister\RenameRegisterCommand;
use App\Income\Application\Query\GetRegister\GetRegisterQuery;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\RegisterResource;
use Ui\Http\Income\UpdateRegisterInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager renames a register of the organization selected by the X-Organization-Id
 * header (ADR 0022 §2).
 *
 * @implements ProcessorInterface<UpdateRegisterInput, RegisterResource>
 */
final readonly class UpdateRegisterProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateRegisterInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RegisterResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $registerId = $uriVariables['id'];
        \assert(\is_string($registerId) && null !== $data->name);

        $this->commandBus->handle(new RenameRegisterCommand($organizationId, $actorId, $registerId, $data->name));

        /** @var RegisterView $view */
        $view = $this->queryBus->ask(new GetRegisterQuery($organizationId, $actorId, $registerId));

        return RegisterResource::fromView($view);
    }
}
