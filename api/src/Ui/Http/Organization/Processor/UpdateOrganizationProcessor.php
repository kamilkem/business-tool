<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Command\UpdateOrganization\UpdateOrganizationCommand;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\OrganizationResource;
use Ui\Http\Organization\UpdateOrganizationInput;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<UpdateOrganizationInput, OrganizationResource> */
final readonly class UpdateOrganizationProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    /** @param UpdateOrganizationInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrganizationResource
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));
        $userId = $this->currentUser->id();

        $this->commandBus->handle(new UpdateOrganizationCommand(
            organizationId: $id,
            actorId: $userId,
            name: $data->name,
            taxId: $data->taxId,
            countryCode: $data->countryCode,
            currency: $data->currency,
            timezone: $data->timezone,
            csvSeparator: $data->csvSeparator->value,
        ));

        /** @var OrganizationView $view */
        $view = $this->queryBus->ask(new GetOrganizationQuery($id, $userId));

        return OrganizationResource::fromView($view);
    }
}
