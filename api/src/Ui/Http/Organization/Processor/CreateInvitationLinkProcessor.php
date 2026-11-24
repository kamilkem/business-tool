<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Query\GetManageableMember\GetManageableMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Organization\InvitationLinkResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Issues a new invitation link without an e-mail (ADR 0037), with the same checks as a resend
 * (ResendInvitationProcessor). The Ui generates the secret so it can return the link (ADR 0037 §4).
 *
 * @implements ProcessorInterface<null, InvitationLinkResource>
 */
final readonly class CreateInvitationLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private AppLinks $links,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvitationLinkResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $userId = (string) $uriVariables['userId'];

        /** @var MemberView|null $member */
        $member = $this->queryBus->ask(new GetManageableMemberQuery($organizationId, $actorId, $userId));
        if (null === $member) {
            throw OrganizationNotFoundException::forId($organizationId);
        }

        $secret = PasswordTokenSecret::generate();
        $this->commandBus->handle(new SendInvitationCommand(
            tokenId: Uuid::v7()->toRfc4122(),
            userId: $member->userId,
            organizationId: $organizationId,
            role: $member->role,
            invitedById: $actorId,
            secret: $secret->value,
            sendEmail: false,
        ));

        return new InvitationLinkResource(
            link: $this->links->setPassword(AppLinks::appFor(MembershipRole::from($member->role)), $secret),
            validForDays: PasswordTokenPurpose::Invitation->lifetime()->d,
        );
    }
}
