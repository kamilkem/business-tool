<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Organization\Processor\CreateInvitationLinkProcessor;

/**
 * An invitation link for a member who has not set a password yet, for the owner or manager to pass on
 * themselves when e-mail does not reach them (ADR 0037). Shown once: it is never stored in plain text.
 */
#[ApiResource(
    shortName: 'InvitationLink',
    operations: [
        new Post(
            uriTemplate: '/members/{userId}/invitation-link',
            uriVariables: ['userId'],
            requirements: ['userId' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Creates an invitation link for a member who has not set a password yet, to pass on without e-mail.',
                description: 'Issues a new one-time invitation link (valid validForDays days) and sends no e-mail; earlier invitation links, the e-mailed one included, stop working. Whoever opens it sets the member\'s password, so pass it to that person only. Owners create links for anyone, managers for employees only (403 otherwise); a person who is not a member is a 404, one who already has a password or is deactivated a 409.',
                responses: [
                    '200' => new Response('The link; shown once.'),
                    '403' => new Response('The actor may not act on this member.'),
                    '404' => new Response('The person is not a member of the selected organization.'),
                    '409' => new Response('The member has already set a password, or is deactivated.'),
                ],
            ),
            input: false,
            read: false,
            processor: CreateInvitationLinkProcessor::class,
        ),
    ],
)]
final readonly class InvitationLinkResource
{
    public function __construct(
        /** Opens the set-password page of the member's app (admin for owners and managers, time clock for employees). */
        public string $link,
        /** How long the link works, in days, from now. */
        public int $validForDays,
    ) {
    }
}
