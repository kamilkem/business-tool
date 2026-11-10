<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Command\CreateOrganization\CreateOrganizationCommand;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Organization\CreateOrganizationInput;
use Ui\Http\Organization\OrganizationResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<CreateOrganizationInput, OrganizationResource> */
final readonly class CreateOrganizationProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    /** @param CreateOrganizationInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrganizationResource
    {
        $id = Uuid::v7()->toRfc4122();
        $userId = $this->currentUser->id();

        $this->commandBus->handle(new CreateOrganizationCommand(
            organizationId: $id,
            ownerId: $userId,
            name: $data->name,
            taxId: $data->taxId,
            countryCode: $data->countryCode,
            currency: $data->currency,
            timezone: $data->timezone,
        ));

        /** @var OrganizationView $view */
        $view = $this->queryBus->ask(new GetOrganizationQuery($id, $userId));

        return OrganizationResource::fromView($view);
    }
}
