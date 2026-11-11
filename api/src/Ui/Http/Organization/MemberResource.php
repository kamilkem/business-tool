<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Organization\Processor\AddMemberProcessor;
use Ui\Http\Organization\Processor\ResendInvitationProcessor;
use Ui\Http\Organization\Provider\MemberCollectionProvider;

/**
 * People with access to the organization selected by the X-Organization-Id header. Only owners
 * and managers may list and add them and resend invitations; managers act on employees only.
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(
            uriTemplate: '/members',
            paginationEnabled: false,
            provider: MemberCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/members',
            openapi: new Operation(
                summary: 'Adds a person to the selected organization.',
                description: 'An unknown e-mail gets a new account without a password (an invitation e-mail follows); a known one keeps its own name and password. Owners give any role, managers add employees only (403 otherwise); an existing member is a 409.',
            ),
            input: AddMemberInput::class,
            read: false,
            processor: AddMemberProcessor::class,
        ),
        new Post(
            uriTemplate: '/members/{userId}/invitation',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            status: 204,
            openapi: new Operation(
                summary: 'Sends a new invitation e-mail to a member who has not set a password yet.',
                description: 'The new link replaces the previous one. Owners resend to anyone, managers to employees only (403 otherwise); a person who is not a member is a 404, one who already has a password a 409.',
                responses: [
                    '204' => new Response('The invitation e-mail is queued.'),
                    '403' => new Response('The actor may not act on this member.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The member has already set a password.'),
                ],
            ),
            input: false,
            output: false,
            read: false,
            processor: ResendInvitationProcessor::class,
        ),
    ],
)]
final readonly class MemberResource
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public MembershipRole $role,
        /** When the person was added to the organization. */
        public \DateTimeImmutable $joinedAt,
        /** The person was invited but has not set a password yet, so they cannot sign in. */
        public bool $invitationPending,
    ) {
    }

    public static function fromView(MemberView $view): self
    {
        return new self(
            userId: $view->userId,
            email: $view->email,
            firstName: $view->firstName,
            lastName: $view->lastName,
            role: MembershipRole::from($view->role),
            joinedAt: $view->joinedAt,
            invitationPending: $view->invitationPending,
        );
    }
}
