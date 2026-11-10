<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\UpdateOrganizationInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the current state; API Platform then applies the request
 * body onto it. Who may change the settings is decided by the domain (Organization::changeSettings()).
 *
 * @implements ProviderInterface<UpdateOrganizationInput>
 */
final readonly class UpdateOrganizationInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateOrganizationInput
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));

        /** @var OrganizationView|null $view */
        $view = $this->queryBus->ask(new GetOrganizationQuery($id, $this->currentUser->id()));

        return UpdateOrganizationInput::fromView($view ?? throw OrganizationNotFoundException::forId($id));
    }
}
