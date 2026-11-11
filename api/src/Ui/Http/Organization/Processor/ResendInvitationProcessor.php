<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Query\GetManageableMember\GetManageableMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Sends a member of the selected organization a new invitation link (ADR 0013), after checking
 * that the actor may act on them. The User module refuses a member who already has a password.
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class ResendInvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $userId = (string) $uriVariables['userId'];

        /** @var MemberView|null $member */
        $member = $this->queryBus->ask(new GetManageableMemberQuery($organizationId, $actorId, $userId));
        if (null === $member) {
            throw OrganizationNotFoundException::forId($organizationId);
        }

        $this->commandBus->handle(new SendInvitationCommand(
            tokenId: Uuid::v7()->toRfc4122(),
            userId: $member->userId,
            organizationId: $organizationId,
            role: $member->role,
            invitedById: $actorId,
        ));

        return null;
    }
}
