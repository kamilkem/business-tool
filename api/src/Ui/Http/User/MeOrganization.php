<?php

declare(strict_types=1);

namespace Ui\Http\User;

final readonly class MeOrganization
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
