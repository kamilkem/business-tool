<?php

declare(strict_types=1);

namespace App\User\Application\Command\SendInvitation;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Domain\Exception\PasswordAlreadySetException;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SendInvitationHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordTokenRepositoryInterface $tokens,
        private QueryBusInterface $queryBus,
        private TransactionalMailerInterface $mailer,
        private AppLinks $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserNotFoundException
     * @throws PasswordAlreadySetException  when the user can already sign in
     * @throws OrganizationNotFoundException when the organization does not exist or the inviter is not a member
     */
    public function __invoke(SendInvitationCommand $command): void
    {
        $user = $this->users->findById(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forId($command->userId);
        if ($user->hasPassword()) {
            throw PasswordAlreadySetException::forUser($command->userId);
        }

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($command->organizationId, $command->invitedById));
        if (null === $organization) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }
        $inviter = $this->users->findById(Uuid::fromString($command->invitedById))
            ?? throw UserNotFoundException::forId($command->invitedById);

        $now = $this->clock->now();
        foreach ($this->tokens->findUnusedByUser($user->getId(), PasswordTokenPurpose::Invitation) as $previous) {
            $previous->invalidate($now);
        }
        $secret = PasswordTokenSecret::generate();
        $token = new PasswordToken(Uuid::fromString($command->tokenId), $user, PasswordTokenPurpose::Invitation, $secret, $now);
        $this->tokens->add($token);

        // Queued only after the token is saved (adr/0012): the link in the e-mail always works. It is
        // in the language chosen for the invitee on add-member, else the inviter's own (ADR 0028 §6).
        $this->mailer->send(new TransactionalEmail(
            to: $user->getEmail(),
            locale: TransactionalEmail::localeOf($user->getLanguage() ?? $inviter->getLanguage()),
            subject: 'invitation.subject',
            template: 'email/invitation.html.twig',
            context: [
                'firstName' => $user->getFirstName(),
                'organizationName' => $organization->name,
                'inviterName' => trim($inviter->getFirstName().' '.$inviter->getLastName()),
                'link' => $this->links->setPassword(AppLinks::appFor(MembershipRole::from($command->role)), $secret),
                'validForDays' => PasswordTokenPurpose::Invitation->lifetime()->d,
            ],
            subjectParameters: ['organizationName' => $organization->name],
        ));
    }
}
