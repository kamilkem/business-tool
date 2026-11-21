<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Language\Language;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PasswordResetApiTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'correct horse battery';

    public function test_it_answers_known_and_unknown_emails_alike_and_mails_only_the_known_one(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);

        $known = self::requestReset('max@example.com', 'admin');
        self::assertQueuedEmailCount(1);
        $unknown = self::requestReset('nobody@example.com', 'admin');
        self::assertQueuedEmailCount(0);

        self::assertSame(202, $known->getStatusCode());
        self::assertSame([$known->getStatusCode(), $known->getContent()], [$unknown->getStatusCode(), $unknown->getContent()]);
        self::assertSame('', $known->getContent());
    }

    /** @return iterable<string, array{string, string}> */
    public static function apps(): iterable
    {
        yield 'admin panel' => ['admin', 'ADMIN_URL'];
        yield 'time clock' => ['timeclock', 'TIMECLOCK_URL'];
    }

    #[DataProvider('apps')]
    public function test_it_mails_a_link_to_the_set_password_page_of_the_requested_app(string $app, string $baseUrlVariable): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);

        self::requestReset('Max@Example.com', $app);

        $email = self::queuedEmail();
        self::assertSame('max@example.com', $email->getTo()[0]->getAddress());
        $baseUrl = rtrim(\is_string($_SERVER[$baseUrlVariable] ?? null) ? $_SERVER[$baseUrlVariable] : '', '/');
        self::assertNotSame('', $baseUrl);
        self::assertStringContainsString('href="'.$baseUrl.'/set-password#token=', (string) $email->getHtmlBody());
    }

    public function test_it_mails_the_link_in_the_persons_own_language(): void
    {
        $user = UserFactory::createOne(['email' => 'max@example.com', 'firstName' => 'Max']);
        $user->changeLanguage(Language::Polish);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::requestReset('max@example.com', 'admin');

        $email = self::queuedEmail();
        self::assertEmailSubjectContains($email, 'Zresetuj hasło do Business Tool');
        self::assertEmailHtmlBodyContains($email, 'Cześć Max,');
        self::assertEmailHtmlBodyContains($email, 'wygasa za 60 minut.');
    }

    public function test_it_lets_the_person_set_a_new_password_with_the_link(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);

        self::requestReset('max@example.com', 'admin');
        self::setPassword(self::secretFrom(self::queuedEmail()));

        self::assertResponseStatusCodeSame(204);
        self::assertCanSignIn('max@example.com', self::NEW_PASSWORD);
    }

    public function test_it_works_for_an_invited_user_who_never_set_a_password(): void
    {
        UserFactory::new()->invited()->create(['email' => 'eve@example.com']);

        self::requestReset('eve@example.com', 'timeclock');
        self::setPassword(self::secretFrom(self::queuedEmail()));

        self::assertResponseStatusCodeSame(204);
        self::assertCanSignIn('eve@example.com', self::NEW_PASSWORD);
    }

    public function test_it_mails_a_member_deactivated_in_every_organization_since_users_are_global(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::requestReset($employee->getEmail(), 'timeclock');

        self::assertResponseStatusCodeSame(202);
        self::assertQueuedEmailCount(1);
    }

    public function test_it_expires_the_link_after_one_hour(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);
        self::mockTime('2026-11-11 12:00:00');

        self::requestReset('max@example.com', 'admin');
        $expired = self::secretFrom(self::queuedEmail());
        self::mockTime('+60 minutes');
        self::setPassword($expired);
        self::assertResponseStatusCodeSame(404);

        self::requestReset('max@example.com', 'admin');
        $fresh = self::secretFrom(self::queuedEmail());
        self::mockTime('+59 minutes');
        self::setPassword($fresh);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_stops_older_reset_links_from_working(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);

        self::requestReset('max@example.com', 'admin');
        $older = self::secretFrom(self::queuedEmail());
        self::requestReset('max@example.com', 'admin');
        $newer = self::secretFrom(self::queuedEmail());

        self::setPassword($older);
        self::assertResponseStatusCodeSame(404);
        self::setPassword($newer);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_limits_requests_per_email_whether_or_not_it_has_an_account(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);
        $client = static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']]);
        $client->disableReboot(); // the limiter's state lives in the kernel (in-memory storage in tests)

        $answers = [];
        foreach (['max@example.com', 'nobody@example.com'] as $email) {
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $client->request('POST', '/api/auth/password-reset', ['json' => ['email' => $email, 'app' => 'admin']]);
                self::assertResponseStatusCodeSame(202);
            }
            // Also counted under another spelling of the same address.
            $response = $client->request('POST', '/api/auth/password-reset', ['json' => ['email' => strtoupper($email), 'app' => 'admin']]);
            self::assertResponseStatusCodeSame(429);
            self::assertQueuedEmailCount(0);
            self::assertGreaterThan(0, (int) $response->getHeaders(false)['retry-after'][0]);
            $answers[$email] = $response->toArray(false)['detail'] ?? null;
        }

        self::assertSame($answers['max@example.com'], $answers['nobody@example.com']);
        $client->request('POST', '/api/auth/password-reset', ['json' => ['email' => 'someone.else@example.com', 'app' => 'admin']]);
        self::assertResponseStatusCodeSame(202);
    }

    public function test_it_limits_requests_per_client_ip(): void
    {
        UserFactory::createOne(['email' => 'max@example.com']);
        $client = static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']]);
        $client->disableReboot();

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $client->request('POST', '/api/auth/password-reset', ['json' => ['email' => 'guess-'.$attempt.'@example.com', 'app' => 'admin']]);
            self::assertResponseStatusCodeSame(202);
        }
        $response = $client->request('POST', '/api/auth/password-reset', ['json' => ['email' => 'max@example.com', 'app' => 'admin']]);

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $response->getHeaders(false)['retry-after'][0]);
        self::assertQueuedEmailCount(0);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'missing e-mail' => [['app' => 'admin'], 'email'];
        yield 'blank e-mail' => [['email' => ' ', 'app' => 'admin'], 'email'];
        yield 'not an e-mail' => [['email' => 'max', 'app' => 'admin'], 'email'];
        yield 'missing app' => [['email' => 'max@example.com'], 'app'];
        yield 'unknown app' => [['email' => 'max@example.com', 'app' => 'https://evil.example'], 'app'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRequests')]
    public function test_it_validates_the_request(array $payload, string $invalidField): void
    {
        $response = static::createClient()->request('POST', '/api/auth/password-reset', [
            'json' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($invalidField, array_column($response->toArray(false)['violations'], 'propertyPath'));
        self::assertQueuedEmailCount(0);
    }

    private static function requestReset(string $email, string $app): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/auth/password-reset', [
            'json' => ['email' => $email, 'app' => $app],
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    private static function setPassword(string $secret): void
    {
        static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => ['token' => $secret, 'password' => self::NEW_PASSWORD],
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    private static function assertCanSignIn(string $email, string $password): void
    {
        static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => $email, 'password' => $password]]);
        self::assertResponseIsSuccessful();
    }

    private static function queuedEmail(): Email
    {
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /** The secret from the link in the e-mail, as the set-password page reads it. */
    private static function secretFrom(Email $email): string
    {
        self::assertSame(1, preg_match('~/set-password#token=([A-Za-z0-9_-]+)"~', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }
}
