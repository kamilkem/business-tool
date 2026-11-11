<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetUserByEmail;

use App\Shared\Application\Query\QueryInterface;

/**
 * The user with this e-mail, or null when there is none. For use cases that act on a person by
 * e-mail (adding a member), never exposed as an endpoint of its own.
 */
final readonly class GetUserByEmailQuery implements QueryInterface
{
    public function __construct(
        public string $email,
    ) {
    }
}
