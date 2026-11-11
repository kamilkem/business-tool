<?php

declare(strict_types=1);

namespace Ui\Http\User;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use Ui\Http\User\Processor\SetPasswordProcessor;

/**
 * Public endpoints that set a password with an e-mailed one-time link (ADR 0013). They answer with
 * no body, so this resource has no fields; nobody is signed in by them.
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
    ],
)]
final readonly class PasswordResource
{
}
