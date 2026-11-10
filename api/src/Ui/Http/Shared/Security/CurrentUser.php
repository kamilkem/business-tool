<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Security;

use App\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The signed-in user as seen by providers and processors: they pass its id into queries and
 * commands, never the entity. Every /api route except login and docs requires authentication
 * (security.yaml), so a missing user here is an access error.
 */
final readonly class CurrentUser
{
    public function __construct(private Security $security)
    {
    }

    public function id(): string
    {
        return $this->user()->getId()->toRfc4122();
    }

    public function user(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new AccessDeniedHttpException();
    }
}
