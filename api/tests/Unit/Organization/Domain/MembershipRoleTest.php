<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MembershipRole::class)]
final class MembershipRoleTest extends TestCase
{
    /** @return iterable<string, array{MembershipRole, list<MembershipRole>}> */
    public static function assignableRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, [MembershipRole::Owner, MembershipRole::Manager, MembershipRole::Employee]];
        yield 'manager' => [MembershipRole::Manager, [MembershipRole::Employee]];
        yield 'employee' => [MembershipRole::Employee, []];
    }

    /** @param list<MembershipRole> $expected */
    #[DataProvider('assignableRoles')]
    public function test_it_knows_which_roles_a_role_may_give(MembershipRole $role, array $expected): void
    {
        $assignable = array_values(array_filter(MembershipRole::cases(), $role->canAssign(...)));

        self::assertSame($expected, $assignable);
    }
}
