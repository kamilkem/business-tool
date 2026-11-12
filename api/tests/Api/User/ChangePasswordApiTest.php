<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use ApiPlatform\Test\Client;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\User\Domain\Model\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Ui\Http\User\Security\RefreshTokenCookie;

/**
 * POST /api/me/password (#12). User-level, not tenant data: no organization header, so no tenant
 * isolation case; the only user it can touch is the signed-in one.
 */
final class ChangePasswordApiTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'correct horse battery';

    public function test_it_changes_the_password_so_only_the_new_one_signs_in(): void
    {
        $user = UserFactory::createOne(['email' => 'eve@example.com']);

        $response = self::changePassword($user, UserFactory::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::secureClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'eve@example.com', 'password' => self::NEW_PASSWORD]]);
        self::assertResponseIsSuccessful();
        self::secureClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'eve@example.com', 'password' => UserFactory::PASSWORD]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_works_for_a_member_without_selecting_an_organization(): void
    {
        [$employee] = self::memberWithRole(MembershipRole::Employee);

        self::changePassword($employee, UserFactory::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_ends_the_other_sessions_and_starts_a_new_one_for_this_client(): void
    {
        $user = UserFactory::createOne();
        $phone = self::signIn($user);
        $laptop = self::signIn($user);
        $someoneElse = self::signIn(UserFactory::createOne());

        $response = self::changePassword($user, UserFactory::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
        self::refresh($phone);
        self::assertResponseStatusCodeSame(401);
        self::refresh($laptop);
        self::assertResponseStatusCodeSame(401);
        self::refresh($someoneElse);
        self::assertResponseIsSuccessful();

        $cookie = self::refreshCookieOf($response);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame(RefreshTokenCookie::PATH, $cookie->getPath());
        self::refresh((string) $cookie->getValue());
        self::assertResponseIsSuccessful();
    }

    public function test_it_emails_the_user_that_the_password_was_changed(): void
    {
        $user = UserFactory::createOne(['email' => 'eve@example.com', 'firstName' => 'Eve']);

        self::changePassword($user, UserFactory::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'eve@example.com');
        self::assertEmailSubjectContains($email, 'Your Business Tool password was changed');
        self::assertEmailHtmlBodyContains($email, 'Hello Eve');
        self::assertEmailHtmlBodyContains($email, 'Forgot password?');
    }

    public function test_it_refuses_a_wrong_current_password_on_its_field_and_changes_nothing(): void
    {
        $user = UserFactory::createOne(['email' => 'eve@example.com']);
        $session = self::signIn($user);

        $response = self::changePassword($user, 'not my password', self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        $violations = $response->toArray(false)['violations'];
        self::assertSame(['currentPassword'], array_column($violations, 'propertyPath'));
        self::assertSame('The current password is wrong.', $violations[0]['message']);
        self::assertQueuedEmailCount(0);
        self::refresh($session);
        self::assertResponseIsSuccessful();
        self::secureClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'eve@example.com', 'password' => UserFactory::PASSWORD]]);
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'new password of 7 characters' => [['currentPassword' => UserFactory::PASSWORD, 'newPassword' => str_repeat('a', 7)], 'newPassword'];
        // 2048 characters, 4098 bytes: more than the password hasher takes.
        yield 'new password of 4098 bytes' => [['currentPassword' => UserFactory::PASSWORD, 'newPassword' => 'aa'.str_repeat('ż', 2048)], 'newPassword'];
        yield 'missing new password' => [['currentPassword' => UserFactory::PASSWORD], 'newPassword'];
        yield 'blank current password' => [['currentPassword' => '', 'newPassword' => self::NEW_PASSWORD], 'currentPassword'];
        yield 'missing current password' => [['newPassword' => self::NEW_PASSWORD], 'currentPassword'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRequests')]
    public function test_it_validates_the_request(array $payload, string $invalidField): void
    {
        $user = UserFactory::createOne();

        $response = static::clientFor($user)->request('POST', '/api/me/password', ['json' => $payload]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($invalidField, array_column($response->toArray(false)['violations'], 'propertyPath'));
        self::assertQueuedEmailCount(0);
    }

    public function test_it_requires_authentication(): void
    {
        static::createClient()->request('POST', '/api/me/password', [
            'json' => ['currentPassword' => UserFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD],
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private static function changePassword(User $user, string $currentPassword, string $newPassword): ResponseInterface
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return self::secureClient()->request('POST', '/api/me/password', [
            'json' => ['currentPassword' => $currentPassword, 'newPassword' => $newPassword],
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
        ]);
    }

    private static function secureClient(): Client
    {
        return static::createClient(defaultOptions: ['base_uri' => 'https://localhost']);
    }

    /** @return string the refresh token cookie set by a successful sign-in */
    private static function signIn(User $user): string
    {
        $response = self::secureClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => $user->getEmail(), 'password' => UserFactory::PASSWORD],
        ]);
        self::assertResponseIsSuccessful();

        return self::refreshCookieOf($response)?->getValue() ?? self::fail('Refresh token cookie expected.');
    }

    private static function refresh(string $cookie): ResponseInterface
    {
        $client = self::secureClient();
        $client->getKernelBrowser()->getCookieJar()->set(
            new BrowserCookie(RefreshTokenCookie::NAME, $cookie, null, RefreshTokenCookie::PATH, 'localhost', true, true),
        );

        return $client->request('POST', '/api/auth/refresh');
    }

    private static function refreshCookieOf(ResponseInterface $response): ?Cookie
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            $cookie = Cookie::fromString($header);
            if (RefreshTokenCookie::NAME === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
