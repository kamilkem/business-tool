<?php

declare(strict_types=1);

namespace App\Organization\Domain\Repository;

use App\Organization\Domain\Model\Membership;
use Symfony\Component\Uid\Uuid;

/** Read access to memberships across organizations; writes go through the Organization aggregate. */
interface MembershipRepositoryInterface
{
    /** The user's membership in the organization, active or deactivated. */
    public function findOne(Uuid $userId, Uuid $organizationId): ?Membership;

    /** @return list<Membership> the user's active memberships, by organization name */
    public function findActiveByUser(Uuid $userId): array;
}
