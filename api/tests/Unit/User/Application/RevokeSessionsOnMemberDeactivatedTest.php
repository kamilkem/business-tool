<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Organization\Domain\Event\MemberDeactivated;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryMembershipRepository;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\User\Application\EventHandler\RevokeSessionsOnMemberDeactivated;
use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RevokeSessionsOnMemberDeactivated::class)]
final class RevokeSessionsOnMemberDeactivatedTest extends TestCase
{
    private const string NOW = '2026-11-12 12:00:00';

    private InMemoryOrganizationRepository $organizations;
    private InMemoryRefreshTokenRepository $tokens;
    private User $owner;
    private User $employee;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->tokens = new InMemoryRefreshTokenRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_revokes_every_session_once_no_active_membership_is_left(): void
    {
        $phone = $this->signIn($this->employee);
        $laptop = $this->signIn($this->employee);
        $owners = $this->signIn($this->owner);
        OrganizationMother::deactivateMember($this->organization, $this->employee);

        $this->handle($this->employee);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $phone->getRevokedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $laptop->getRevokedAt());
        self::assertNull($owners->getRevokedAt());
    }

    public function test_it_keeps_the_sessions_while_the_person_is_an_active_member_elsewhere(): void
    {
        $other = OrganizationMother::ownedBy(UserMother::any('boss@example.com'), 'Big Bar');
        OrganizationMother::addMember($other, $this->employee, MembershipRole::Employee);
        $this->organizations->add($other);
        $session = $this->signIn($this->employee);
        OrganizationMother::deactivateMember($this->organization, $this->employee);

        $this->handle($this->employee);

        self::assertNull($session->getRevokedAt());
    }

    private function signIn(User $user): RefreshToken
    {
        $token = RefreshToken::issueAtSignIn(Uuid::v7(), $user, Uuid::v7(), RefreshTokenSecret::generate(), new \DateTimeImmutable('2026-11-12 08:00:00'));
        $this->tokens->add($token);

        return $token;
    }

    private function handle(User $user): void
    {
        new RevokeSessionsOnMemberDeactivated(new InMemoryMembershipRepository($this->organizations), $this->tokens, new MockClock(self::NOW))(
            new MemberDeactivated($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()),
        );
    }
}
