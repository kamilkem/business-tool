<?php

declare(strict_types=1);

namespace App\Organization\Domain\Repository;

use App\Organization\Domain\Model\Membership;
use Symfony\Component\Uid\Uuid;

/** Read access to memberships across organizations; writes go through the Organization aggregate. */
interface MembershipRepositoryInterface
{
    public function findOne(Uuid $userId, Uuid $organizationId): ?Membership;

    /** @return list<Membership> by organization name */
    public function findByUser(Uuid $userId): array;
}
