<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Command\AddMember\AddMemberCommand;
use App\Organization\Application\Query\CanAddMember\CanAddMemberQuery;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\CreateInvitedUser\CreateInvitedUserCommand;
use App\User\Application\Query\GetUserByEmail\GetUserByEmailQuery;
use App\User\Application\Query\GetUserByEmail\UserView;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Organization\AddMemberInput;
use Ui\Http\Organization\MemberResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Adds a person to the organization selected by the X-Organization-Id header (ADR 0013): reuses
 * the user with that e-mail, or creates one without a password, then adds the membership. The
 * actor's permission is checked first, so a refused request creates no user.
 *
 * @implements ProcessorInterface<AddMemberInput, MemberResource>
 */
final readonly class AddMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddMemberInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        $this->queryBus->ask(new CanAddMemberQuery($organizationId, $actorId, $data->role->value))
            ?? throw OrganizationNotFoundException::forId($organizationId);

        /** @var UserView|null $user */
        $user = $this->queryBus->ask(new GetUserByEmailQuery($data->email));
        $userId = $user?->id;
        if (null === $userId) {
            $userId = Uuid::v7()->toRfc4122();
            $this->commandBus->handle(new CreateInvitedUserCommand($userId, $data->email, $data->firstName, $data->lastName));
        }

        $this->commandBus->handle(new AddMemberCommand($organizationId, $actorId, $userId, $data->role->value));

        /** @var MemberView $view */
        $view = $this->queryBus->ask(new GetMemberQuery($organizationId, $actorId, $userId));

        return MemberResource::fromView($view);
    }
}
