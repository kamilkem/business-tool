<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Ui\Http\User\Security\RefreshTokenCookie;

/** Sign-in cookie, POST /api/auth/refresh and POST /api/auth/logout (ADR 0020). */
final class RefreshTokenApiTest extends ApiTestCase
{
    public function test_it_sets_an_http_only_strict_cookie_for_auth_paths_at_sign_in(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 08:00:00');

        $response = self::secureClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => $user->getEmail(), 'password' => UserFactory::PASSWORD],
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $response->toArray());
        $cookie = self::refreshCookieOf($response);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame('/api/auth', $cookie->getPath());
        self::assertSame((new \DateTimeImmutable('2026-12-12 08:00:00'))->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_it_gives_no_cookie_for_a_wrong_password(): void
    {
        $user = UserFactory::createOne();

        $response = self::secureClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => $user->getEmail(), 'password' => 'wrong password'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertNull(self::refreshCookieOf($response));
    }

    public function test_it_exchanges_the_cookie_for_a_working_jwt_and_a_rotated_cookie(): void
    {
        $user = UserFactory::createOne(['email' => 'jane@example.com']);
        $signedIn = self::signIn($user);

        $response = self::refresh($signedIn);

        self::assertResponseIsSuccessful();
        $rotated = self::refreshCookieOf($response)?->getValue();
        self::assertNotNull($rotated);
        self::assertNotSame($signedIn, $rotated);
        $me = self::secureClient()->request('GET', '/api/me', ['headers' => [
            'Authorization' => 'Bearer '.$response->toArray()['token'],
            'Accept' => 'application/json',
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame('jane@example.com', $me->toArray()['email']);
    }

    public function test_it_keeps_a_session_alive_past_a_shift_and_beyond_30_days_while_it_is_used(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 16:00:00');
        $cookie = self::signIn($user);

        foreach (['2026-11-13 02:00:00', '2026-12-05 16:00:00', '2027-01-02 16:00:00'] as $moment) {
            self::mockTime($moment);
            $cookie = self::refreshCookieOf(self::refresh($cookie))?->getValue();
            self::assertResponseIsSuccessful();
            self::assertNotNull($cookie);
        }
    }

    public function test_it_refuses_a_cookie_unused_for_30_days(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 16:00:00');
        $cookie = self::signIn($user);
        self::mockTime('2026-12-12 16:00:00');

        self::assertRefused(self::refresh($cookie));
    }

    public function test_it_ends_every_session_90_days_after_sign_in(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 16:00:00');
        $cookie = self::signIn($user);
        foreach (['2026-12-10 16:00:00', '2027-01-07 16:00:00', '2027-02-04 16:00:00'] as $moment) {
            self::mockTime($moment);
            $cookie = self::refreshCookieOf(self::refresh($cookie))?->getValue() ?? self::fail('Refresh expected.');
        }
        self::mockTime('2027-02-10 16:00:00'); // 90 days after the sign-in

        self::assertRefused(self::refresh($cookie));
    }

    public function test_it_refuses_a_missing_or_unknown_cookie_and_clears_it(): void
    {
        self::assertRefused(self::refresh(null));
        self::assertRefused(self::refresh(RefreshTokenSecret::generate()->value));
    }

    public function test_it_accepts_a_rotated_cookie_again_within_30_seconds(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 16:00:00');
        $first = self::signIn($user);
        $second = self::refreshCookieOf(self::refresh($first))?->getValue() ?? self::fail('Refresh expected.');
        self::mockTime('2026-11-12 16:00:30');

        self::refresh($first);

        self::assertResponseIsSuccessful();
        self::refresh($second);
        self::assertResponseIsSuccessful();
    }

    public function test_it_revokes_the_whole_session_when_a_rotated_cookie_is_reused(): void
    {
        $user = UserFactory::createOne();
        self::mockTime('2026-11-12 16:00:00');
        $stolen = self::signIn($user);
        $current = self::refreshCookieOf(self::refresh($stolen))?->getValue() ?? self::fail('Refresh expected.');
        $otherDevice = self::signIn($user);
        self::mockTime('2026-11-12 16:00:31');

        self::assertRefused(self::refresh($stolen));

        self::assertRefused(self::refresh($current));
        self::refresh($otherDevice);
        self::assertResponseIsSuccessful();
    }

    public function test_it_signs_out_one_session_and_clears_the_cookie(): void
    {
        $user = UserFactory::createOne();
        $signedOut = self::signIn($user);
        $otherApp = self::signIn($user);

        $response = self::logout($signedOut);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertTrue(self::refreshCookieOf($response)?->isCleared());
        self::assertRefused(self::refresh($signedOut));
        self::refresh($otherApp);
        self::assertResponseIsSuccessful();
    }

    public function test_it_signs_out_without_a_jwt_and_with_a_missing_or_unknown_cookie(): void
    {
        self::logout(null);
        self::assertResponseStatusCodeSame(204);
        self::logout(RefreshTokenSecret::generate()->value);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_ends_every_session_of_the_user_when_the_password_is_reset(): void
    {
        $user = UserFactory::createOne();
        $admin = self::signIn($user);
        $timeclock = self::signIn($user);
        $someoneElse = self::signIn(UserFactory::createOne());

        static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => ['token' => self::issueResetToken($user), 'password' => 'correct horse battery'],
            'headers' => ['Accept' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(204);

        self::assertRefused(self::refresh($admin));
        self::assertRefused(self::refresh($timeclock));
        self::refresh($someoneElse);
        self::assertResponseIsSuccessful();
    }

    public function test_it_ends_the_sessions_of_a_member_deactivated_in_their_only_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, UserFactory::createOne());
        $cookie = self::signIn($employee);

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/deactivate');
        self::assertResponseStatusCodeSame(204);

        self::assertRefused(self::refresh($cookie));
    }

    public function test_it_keeps_the_sessions_of_a_member_still_active_in_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, UserFactory::createOne());
        self::addMember(OrganizationFactory::createOne(), $employee);
        $cookie = self::signIn($employee);

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/deactivate');
        self::assertResponseStatusCodeSame(204);

        self::refresh($cookie);
        self::assertResponseIsSuccessful();
    }

    /** Secure cookies only travel over HTTPS, in the test browser as in real ones. */
    private static function secureClient(): \ApiPlatform\Test\Client
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

    private static function refresh(?string $cookie): ResponseInterface
    {
        return self::withCookie($cookie)->request('POST', '/api/auth/refresh');
    }

    private static function logout(?string $cookie): ResponseInterface
    {
        return self::withCookie($cookie)->request('POST', '/api/auth/logout');
    }

    private static function withCookie(?string $cookie): \ApiPlatform\Test\Client
    {
        $client = self::secureClient();
        if (null !== $cookie) {
            $client->getKernelBrowser()->getCookieJar()->set(
                new BrowserCookie(RefreshTokenCookie::NAME, $cookie, null, RefreshTokenCookie::PATH, 'localhost', true, true),
            );
        }

        return $client;
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

    private static function assertRefused(ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Your session has expired. Sign in again.', $response->toArray(false)['message']);
        self::assertTrue(self::refreshCookieOf($response)?->isCleared(), 'The cookie should be cleared.');
    }

    private static function addMember(Organization $organization, User $user): User
    {
        OrganizationMother::addMember($organization, $user, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    /** @return string the secret of a password reset link, issued now */
    private static function issueResetToken(User $user): string
    {
        $secret = PasswordTokenSecret::generate();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managedUser = $entityManager->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
        $entityManager->persist(new PasswordToken(Uuid::v7(), $managedUser, PasswordTokenPurpose::PasswordReset, $secret, Clock::get()->now()));
        $entityManager->flush();

        return $secret->value;
    }
}
