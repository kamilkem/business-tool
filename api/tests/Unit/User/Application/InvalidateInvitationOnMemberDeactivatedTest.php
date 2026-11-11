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
use App\User\Application\EventHandler\InvalidateInvitationOnMemberDeactivated;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(InvalidateInvitationOnMemberDeactivated::class)]
final class InvalidateInvitationOnMemberDeactivatedTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    private InMemoryUserRepository $users;
    private InMemoryOrganizationRepository $organizations;
    private InMemoryPasswordTokenRepository $tokens;
    private User $owner;
    private User $invited;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->organizations = new InMemoryOrganizationRepository();
        $this->tokens = new InMemoryPasswordTokenRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->owner->changePassword('hash');
        $this->invited = UserMother::any('eve@example.com');
        $this->users->add($this->owner);
        $this->users->add($this->invited);
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->invited, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_invalidates_the_pending_invitation_of_a_deactivated_member(): void
    {
        $invitation = $this->issue($this->invited);
        $otherUsers = $this->issue($this->owner);
        OrganizationMother::deactivateMember($this->organization, $this->invited);

        $this->handle($this->invited);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $invitation->getInvalidatedAt());
        self::assertNull($otherUsers->getInvalidatedAt());
    }

    public function test_it_keeps_the_invitation_while_the_person_is_an_active_member_elsewhere(): void
    {
        $other = OrganizationMother::ownedBy(UserMother::any('boss@example.com'), 'Big Bar');
        OrganizationMother::addMember($other, $this->invited, MembershipRole::Employee);
        $this->organizations->add($other);
        $invitation = $this->issue($this->invited);
        OrganizationMother::deactivateMember($this->organization, $this->invited);

        $this->handle($this->invited);

        self::assertNull($invitation->getInvalidatedAt());
    }

    public function test_it_leaves_the_tokens_of_someone_who_has_a_password(): void
    {
        $manager = UserMother::any('max@example.com');
        $manager->changePassword('hash');
        $this->users->add($manager);
        OrganizationMother::addMember($this->organization, $manager, MembershipRole::Manager);
        $reset = $this->issue($manager, PasswordTokenPurpose::PasswordReset);
        OrganizationMother::deactivateMember($this->organization, $manager);

        $this->handle($manager);

        self::assertNull($reset->getInvalidatedAt());
    }

    public function test_it_refuses_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->handler()(new MemberDeactivated($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), $this->owner->getId()->toRfc4122()));
    }

    private function issue(User $user, PasswordTokenPurpose $purpose = PasswordTokenPurpose::Invitation): PasswordToken
    {
        $token = new PasswordToken(Uuid::v7(), $user, $purpose, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-10 12:00:00'));
        $this->tokens->add($token);

        return $token;
    }

    private function handle(User $user): void
    {
        $this->handler()(new MemberDeactivated(
            $this->organization->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $this->owner->getId()->toRfc4122(),
        ));
    }

    private function handler(): InvalidateInvitationOnMemberDeactivated
    {
        return new InvalidateInvitationOnMemberDeactivated(
            $this->users,
            new InMemoryMembershipRepository($this->organizations),
            $this->tokens,
            new MockClock(self::NOW),
        );
    }
}
