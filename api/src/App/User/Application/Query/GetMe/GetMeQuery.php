<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetMe;

use App\Shared\Application\Query\QueryInterface;

final readonly class GetMeQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
