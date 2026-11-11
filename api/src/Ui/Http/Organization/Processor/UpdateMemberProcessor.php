<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Command\ChangeMemberRole\ChangeMemberRoleCommand;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\MemberResource;
use Ui\Http\Organization\UpdateMemberInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Changes a member's role in the organization selected by the X-Organization-Id header. The
 * response is read as the actor, who always sees their own membership, so an owner who stepped
 * down to employee still gets it back.
 *
 * @implements ProcessorInterface<UpdateMemberInput, MemberResource>
 */
final readonly class UpdateMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateMemberInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $userId = (string) $uriVariables['userId'];

        $this->commandBus->handle(new ChangeMemberRoleCommand($organizationId, $actorId, $userId, $data->role->value));

        /** @var MemberView $view */
        $view = $this->queryBus->ask(new GetMemberQuery($organizationId, $actorId, $userId));

        return MemberResource::fromView($view);
    }
}
