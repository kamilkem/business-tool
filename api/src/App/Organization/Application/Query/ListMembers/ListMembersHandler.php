<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListMembers;

use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListMembersHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * @return list<MemberView>|null by last name, first name, then e-mail
     *
     * @throws OrganizationManagementForbiddenException when the user is an employee
     */
    public function __invoke(ListMembersQuery $query): ?array
    {
        if (!Uuid::isValid($query->organizationId) || !Uuid::isValid($query->userId)) {
            return null;
        }

        $userId = Uuid::fromString($query->userId);
        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || !$organization->isActiveMember($userId)) {
            return null;
        }

        $members = array_map(MemberView::fromMembership(...), $organization->membersVisibleTo($userId, $query->includeDeactivated));
        usort($members, static fn (MemberView $a, MemberView $b): int => self::sortKey($a) <=> self::sortKey($b));

        return $members;
    }

    /** @return list<string> */
    private static function sortKey(MemberView $member): array
    {
        return [mb_strtolower($member->lastName), mb_strtolower($member->firstName), $member->email];
    }
}
