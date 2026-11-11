<?php

declare(strict_types=1);

namespace Ui\Fixture;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\User\Domain\Model\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Local development data. All demo accounts use the password "password".
 *
 *   owner@example.com     owner of "Demo Bar"
 *   manager@example.com   manager of "Demo Bar"
 *   employee@example.com  employee of "Demo Bar"
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_PASSWORD = 'password';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $owner = $this->user($manager, 'owner@example.com', 'Olivia', 'Owner');
        $bar = new Organization(Uuid::v7(), 'Demo Bar', $owner);
        $bar->addMember($owner->getId(), $this->user($manager, 'manager@example.com', 'Max', 'Manager'), MembershipRole::Manager);
        $bar->addMember($owner->getId(), $this->user($manager, 'employee@example.com', 'Eve', 'Employee'), MembershipRole::Employee);
        $manager->persist($bar);

        $manager->flush();
    }

    private function user(ObjectManager $manager, string $email, string $firstName, string $lastName): User
    {
        $user = new User(Uuid::v7(), $email, $firstName, $lastName);
        $user->changePassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
