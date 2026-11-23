<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\User;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * app:organization:create (#219): the way into an empty database and how each new customer starts.
 */
final class CreateOrganizationWithOwnerCommandTest extends ApiTestCase
{
    private const array BAR = [
        '--name' => 'Corner Bar',
        '--country' => 'PL',
        '--currency' => 'PLN',
        '--timezone' => 'Europe/Warsaw',
        '--tax-id' => '5260250995',
    ];

    public function test_it_creates_the_organization_with_a_new_owner_and_e_mails_them_the_link_to_set_their_password(): void
    {
        self::mockTime('2026-11-23 12:00:00');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--owner-email' => ' Olivia@Example.com ',
            '--owner-first-name' => 'Olivia',
            '--owner-last-name' => 'Owner',
            '--owner-language' => 'pl',
            ...self::BAR,
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $owner = $this->user('olivia@example.com');
        self::assertSame(['Olivia', 'Owner', 'pl', false], [$owner->getFirstName(), $owner->getLastName(), $owner->getLanguage()?->value, $owner->hasPassword()]);

        $organization = $this->onlyOrganizationOf($owner);
        self::assertSame(['Corner Bar', '5260250995', 'PL', 'PLN', 'Europe/Warsaw'], [$organization->getName(), $organization->getTaxId(), $organization->getCountryCode(), $organization->getCurrency(), $organization->getTimezone()]);
        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());

        self::assertCount(1, static::getContainer()->get(PasswordTokenRepositoryInterface::class)->findUnusedByUser($owner->getId(), PasswordTokenPurpose::Invitation));
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'olivia@example.com');
        $html = (string) $email->getHtmlBody();
        // In Polish, as chosen for the owner; nobody invited them, so the account was "set up for them".
        self::assertStringContainsString('Utworzyliśmy dla Ciebie konto do zarządzania Corner Bar', $html);
        self::assertStringNotContainsString('zaprasza', $html);
        self::assertMatchesRegularExpression('~/set-password#token=[A-Za-z0-9_-]{43}"~', $html);
        self::assertStringNotContainsString('token=', $tester->getDisplay(), 'The link is e-mailed, never printed.');
    }

    public function test_it_makes_an_existing_user_the_owner_without_e_mailing_one_who_has_a_password(): void
    {
        $existing = UserFactory::createOne(['email' => 'olivia@example.com', 'firstName' => 'Olivia', 'lastName' => 'Owner']);

        $tester = $this->tester();
        $exit = $tester->execute(['--owner-email' => 'olivia@example.com', ...self::BAR], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(MembershipRole::Owner, $this->onlyOrganizationOf($existing)->membershipOf($existing->getId())?->getRole());
        self::assertQueuedEmailCount(0);
        self::assertStringContainsString('already has a password', $tester->getDisplay());
    }

    public function test_it_refuses_invalid_input_and_creates_nothing(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            '--owner-email' => 'not-an-email',
            '--owner-first-name' => 'Olivia',
            '--owner-last-name' => 'Owner',
            ...self::BAR,
            '--country' => 'XX',
            '--timezone' => 'Europe/Nowhere',
        ], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exit);
        $display = $tester->getDisplay();
        foreach (['owner.email', 'organization.countryCode', 'organization.timezone'] as $field) {
            self::assertStringContainsString($field, $display);
        }
        self::assertSame([], static::getContainer()->get(OrganizationRepositoryInterface::class)->findAll());
        self::assertNull(static::getContainer()->get(UserRepositoryInterface::class)->findByEmail('not-an-email'));
        self::assertQueuedEmailCount(0);
    }

    public function test_it_refuses_an_unknown_language(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--owner-email' => 'olivia@example.com', '--owner-first-name' => 'Olivia', '--owner-last-name' => 'Owner', '--owner-language' => 'de', ...self::BAR], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exit);
        self::assertSame([], static::getContainer()->get(OrganizationRepositoryInterface::class)->findAll());
    }

    public function test_it_needs_the_new_owners_name_when_it_cannot_ask_for_it(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--owner-email' => 'olivia@example.com', ...self::BAR], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('--owner-first-name', $tester->getDisplay());
        self::assertNull(static::getContainer()->get(UserRepositoryInterface::class)->findByEmail('olivia@example.com'));
    }

    public function test_it_asks_for_what_is_missing_and_creates_nothing_unless_confirmed(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['olivia@example.com', 'Olivia', 'Owner', 'Corner Bar', 'no']);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], static::getContainer()->get(OrganizationRepositoryInterface::class)->findAll());

        $tester->setInputs(['olivia@example.com', 'Olivia', 'Owner', 'Corner Bar', 'yes']);
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame('Corner Bar', $this->onlyOrganizationOf($this->user('olivia@example.com'))->getName());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:organization:create'));
    }

    private function user(string $email): User
    {
        return static::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email) ?? self::fail("No user $email.");
    }

    private function onlyOrganizationOf(User $user): Organization
    {
        $organizations = static::getContainer()->get(OrganizationRepositoryInterface::class)->findByMember($user->getId());
        self::assertCount(1, $organizations);

        return $organizations[0];
    }
}
