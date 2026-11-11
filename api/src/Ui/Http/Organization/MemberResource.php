<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Organization\Processor\AddMemberProcessor;
use Ui\Http\Organization\Processor\DeactivateMemberProcessor;
use Ui\Http\Organization\Processor\ReactivateMemberProcessor;
use Ui\Http\Organization\Processor\ResendInvitationProcessor;
use Ui\Http\Organization\Processor\UpdateMemberProcessor;
use Ui\Http\Organization\Provider\MemberCollectionProvider;
use Ui\Http\Organization\Provider\UpdateMemberInputProvider;

/**
 * People with access to the organization selected by the X-Organization-Id header. Only owners
 * and managers may list and add them, change their roles, resend invitations, and deactivate and
 * reactivate them; managers act on employees only. A deactivated member keeps their membership (for
 * the history that refers to it) but loses access to the organization.
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(
            uriTemplate: '/members',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the members of the selected organization.',
                description: 'Active members only, unless includeDeactivated is true. Owners and managers only (403 otherwise).',
            ),
            provider: MemberCollectionProvider::class,
            parameters: [
                'includeDeactivated' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list deactivated members.',
                    castToNativeType: true,
                ),
            ],
        ),
        new Post(
            uriTemplate: '/members',
            openapi: new Operation(
                summary: 'Adds a person to the selected organization.',
                description: 'An unknown e-mail gets a new account without a password (an invitation e-mail follows); a known one keeps its own name and password. Owners give any role, managers add employees only (403 otherwise); an existing member is a 409, and so is a deactivated one (reactivate them instead).',
            ),
            input: AddMemberInput::class,
            read: false,
            processor: AddMemberProcessor::class,
        ),
        new Patch(
            uriTemplate: '/members/{userId}',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            openapi: new Operation(
                summary: "Changes a member's role in the selected organization.",
                description: 'Owners give anyone any role, themselves included; managers act on employees only and cannot promote them (403 otherwise). The organization keeps at least one active owner: demoting the last one is a 409, as is changing a deactivated member. A person who is not a member is a 404.',
                responses: [
                    '403' => new Response('The actor may not act on this member or give this role.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The change would leave the organization without an active owner, or the member is deactivated.'),
                ],
            ),
            input: UpdateMemberInput::class,
            provider: UpdateMemberInputProvider::class,
            processor: UpdateMemberProcessor::class,
        ),
        new Post(
            uriTemplate: '/members/{userId}/invitation',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            status: 204,
            openapi: new Operation(
                summary: 'Sends a new invitation e-mail to a member who has not set a password yet.',
                description: 'The new link replaces the previous one. Owners resend to anyone, managers to employees only (403 otherwise); a person who is not a member is a 404, one who already has a password or is deactivated a 409.',
                responses: [
                    '204' => new Response('The invitation e-mail is queued.'),
                    '403' => new Response('The actor may not act on this member.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The member has already set a password, or is deactivated.'),
                ],
            ),
            input: false,
            output: false,
            read: false,
            processor: ResendInvitationProcessor::class,
        ),
        new Post(
            uriTemplate: '/members/{userId}/deactivate',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            status: 204,
            openapi: new Operation(
                summary: "Removes a member's access to the selected organization.",
                description: 'The membership is kept for the history that refers to it: the person no longer sees the organization, and requests that select it are a 403. A pending invitation stops working. Owners deactivate anyone, themselves included; managers employees only (403 otherwise). The organization keeps at least one active owner (409). A person who is not a member is a 404; deactivating a deactivated member changes nothing.',
                responses: [
                    '204' => new Response('The member is deactivated.'),
                    '403' => new Response('The actor may not act on this member.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The member is the last active owner.'),
                ],
            ),
            input: false,
            output: false,
            read: false,
            processor: DeactivateMemberProcessor::class,
        ),
        new Post(
            uriTemplate: '/members/{userId}/reactivate',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            status: 204,
            openapi: new Operation(
                summary: 'Gives a deactivated member their access to the selected organization back, with their previous role.',
                description: 'Owners reactivate anyone, managers employees only (403 otherwise). A person who is not a member is a 404; reactivating an active member changes nothing. An invitation that stopped working on deactivation is not resent: use POST /members/{userId}/invitation.',
                responses: [
                    '204' => new Response('The member is active.'),
                    '403' => new Response('The actor may not act on this member.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                ],
            ),
            input: false,
            output: false,
            read: false,
            processor: ReactivateMemberProcessor::class,
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
        /** When the person's access was removed; null for an active member. */
        public ?\DateTimeImmutable $deactivatedAt,
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
            deactivatedAt: $view->deactivatedAt,
        );
    }
}
