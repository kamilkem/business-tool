<?php

declare(strict_types=1);

namespace Ui\Http\User;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use Ui\Http\User\Processor\RequestPasswordResetProcessor;
use Ui\Http\User\Processor\SetPasswordProcessor;

/**
 * Public endpoints that set a password with an e-mailed one-time link, or ask for such a link to
 * reset a forgotten one (ADR 0013). They answer with no body, so this resource has no fields; nobody
 * is signed in by them.
 */
#[ApiResource(
    shortName: 'Password',
    operations: [
        new Post(
            uriTemplate: '/auth/set-password',
            status: 204,
            openapi: new Operation(
                summary: 'Sets a password with the token from an invitation or password reset link.',
                description: 'Public. Does not sign the person in: send them to sign-in afterwards. An unknown, used or expired token is always the same 404. Rate-limited per client IP (429).',
                responses: [
                    '204' => new Response('The password is set; the person can sign in.'),
                    '404' => new Response('The token is unknown, used or expired.'),
                    '429' => new Response('Too many attempts from this IP address; see Retry-After.'),
                ],
                security: [],
            ),
            input: SetPasswordInput::class,
            output: false,
            read: false,
            processor: SetPasswordProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/password-reset',
            status: 202,
            openapi: new Operation(
                summary: 'E-mails a link to set a new password, if the e-mail has an account.',
                description: 'Public. Always answers 202, whether or not the e-mail has an account, so accounts cannot be discovered. For a known e-mail it issues a reset token valid for 1 hour (older unused reset links stop working) and e-mails a link to the set-password page of the requested app; the new password is then set with POST /api/auth/set-password. Rate-limited per client IP and per e-mail (429).',
                responses: [
                    '202' => new Response('Accepted. If the e-mail has an account, a link is on its way.'),
                    '429' => new Response('Too many requests from this IP address or for this e-mail; see Retry-After.'),
                ],
                security: [],
            ),
            input: RequestPasswordResetInput::class,
            output: false,
            read: false,
            processor: RequestPasswordResetProcessor::class,
        ),
    ],
)]
final readonly class PasswordResource
{
}
