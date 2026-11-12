<?php

declare(strict_types=1);

namespace App\User\Application\EventHandler;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Event\MemberAdded;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tells a person they were added to an organization (ADR 0013): someone without a password gets an
 * invitation with a link to set one; someone who can already sign in gets a notification with a
 * sign-in link, and no token.
 */
final readonly class SendAccessEmailOnMemberAdded implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private TransactionalMailerInterface $mailer,
        private AppLinks $links,
    ) {
    }

    /**
     * @throws UserNotFoundException
     * @throws OrganizationNotFoundException
     */
    public function __invoke(MemberAdded $event): void
    {
        $user = $this->users->findById(Uuid::fromString($event->userId))
            ?? throw UserNotFoundException::forId($event->userId);

        if (!$user->hasPassword()) {
            $this->commandBus->handle(new SendInvitationCommand(
                tokenId: Uuid::v7()->toRfc4122(),
                userId: $event->userId,
                organizationId: $event->organizationId,
                role: $event->role,
                invitedById: $event->addedById,
            ));

            return;
        }

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($event->organizationId, $event->userId));
        if (null === $organization) {
            throw OrganizationNotFoundException::forId($event->organizationId);
        }
        $addedBy = $this->users->findById(Uuid::fromString($event->addedById))
            ?? throw UserNotFoundException::forId($event->addedById);

        $this->mailer->send(new TransactionalEmail(
            to: $user->getEmail(),
            subject: \sprintf('You now have access to %s', $organization->name),
            template: 'email/added_to_organization.html.twig',
            context: [
                'firstName' => $user->getFirstName(),
                'organizationName' => $organization->name,
                'addedByName' => trim($addedBy->getFirstName().' '.$addedBy->getLastName()),
                'link' => $this->links->signIn(AppLinks::appFor(MembershipRole::from($event->role))),
            ],
        ));
    }
}
