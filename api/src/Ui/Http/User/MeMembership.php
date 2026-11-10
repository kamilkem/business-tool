<?php

declare(strict_types=1);

namespace Ui\Http\User;

use App\Organization\Domain\Model\MembershipRole;

final readonly class MeMembership
{
    public function __construct(
        public string $id,
        public MembershipRole $role,
        public MeOrganization $organization,
    ) {
    }
}
