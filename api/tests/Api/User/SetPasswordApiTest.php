<?php

declare(strict_types=1);

namespace App\Tests\Api\User;

use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SetPasswordApiTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'correct horse battery';

    public function test_it_sets_the_password_without_authentication_so_the_person_can_sign_in(): void
    {
        $user = UserFactory::new()->invited()->create(['email' => 'eve@example.com']);
        $secret = self::issueToken($user, PasswordTokenPurpose::Invitation);
        self::mockTime('+1 day');

        $response = self::setPassword($secret, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'eve@example.com', 'password' => self::NEW_PASSWORD],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function test_it_replaces_a_forgotten_password_with_a_reset_token(): void
    {
        $user = UserFactory::createOne(['email' => 'max@example.com']);
        $secret = self::issueToken($user, PasswordTokenPurpose::PasswordReset);
        self::mockTime('+10 minutes');

        self::setPassword($secret, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(204);
        static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'max@example.com', 'password' => UserFactory::PASSWORD],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_answers_unknown_used_and_expired_tokens_alike(): void
    {
        $user = UserFactory::new()->invited()->create();
        self::mockTime();
        $used = self::issueToken($user, PasswordTokenPurpose::Invitation);
        self::setPassword($used, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);
        $expired = self::issueToken($user, PasswordTokenPurpose::PasswordReset); // after the password, so only expiry stops it
        self::mockTime('+61 minutes');

        $answers = [];
        foreach (['unknown' => PasswordTokenSecret::generate()->value, 'used' => $used, 'expired' => $expired] as $case => $secret) {
            $response = self::setPassword($secret, 'another password');
            $body = $response->toArray(false);
            unset($body['trace']); // debug only; differs by where the exception was thrown
            $answers[$case] = [$response->getStatusCode(), $body];
        }

        self::assertSame(404, $answers['unknown'][0]);
        self::assertSame($answers['unknown'], $answers['used']);
        self::assertSame($answers['unknown'], $answers['expired']);
        self::assertSame('This link is invalid or has expired.', $answers['unknown'][1]['detail']);
    }

    public function test_it_invalidates_the_other_links_once_the_password_is_set(): void
    {
        $user = UserFactory::new()->invited()->create();
        $invitation = self::issueToken($user, PasswordTokenPurpose::Invitation);
        $reset = self::issueToken($user, PasswordTokenPurpose::PasswordReset);
        self::mockTime('+5 minutes');

        self::setPassword($invitation, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);
        self::setPassword($reset, 'another password');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'password of 7 characters' => [['token' => 'secret', 'password' => str_repeat('a', 7)], 'password'];
        yield 'password of 4097 characters' => [['token' => 'secret', 'password' => str_repeat('a', 4097)], 'password'];
        // 2048 characters, 4098 bytes: more than the password hasher takes.
        yield 'password of 4098 bytes' => [['token' => 'secret', 'password' => 'aa'.str_repeat('ż', 2048)], 'password'];
        yield 'missing password' => [['token' => 'secret'], 'password'];
        yield 'blank token' => [['token' => '', 'password' => self::NEW_PASSWORD], 'token'];
        yield 'missing token' => [['password' => self::NEW_PASSWORD], 'token'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRequests')]
    public function test_it_validates_the_request(array $payload, string $invalidField): void
    {
        $response = static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($invalidField, array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function test_it_accepts_passwords_from_8_characters_to_4096_bytes_without_composition_rules(): void
    {
        $user = UserFactory::new()->invited()->create();

        self::setPassword(self::issueToken($user, PasswordTokenPurpose::Invitation), 'aaaaaaaa');
        self::assertResponseStatusCodeSame(204);
        self::setPassword(self::issueToken($user, PasswordTokenPurpose::PasswordReset), str_repeat('ż', 2048));
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_limits_attempts_per_client_ip(): void
    {
        $user = UserFactory::new()->invited()->create();
        $valid = self::issueToken($user, PasswordTokenPurpose::Invitation);
        $client = static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']]);
        $client->disableReboot(); // the limiter's state lives in the kernel (array cache in tests)

        for ($attempt = 1; $attempt <= 30; ++$attempt) {
            $client->request('POST', '/api/auth/set-password', ['json' => ['token' => 'guess-'.$attempt, 'password' => self::NEW_PASSWORD]]);
            self::assertResponseStatusCodeSame(404);
        }
        $response = $client->request('POST', '/api/auth/set-password', ['json' => ['token' => $valid, 'password' => self::NEW_PASSWORD]]);

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $response->getHeaders(false)['retry-after'][0]);
        self::assertFalse(self::reloaded($user)->hasPassword());
    }

    private static function setPassword(string $token, string $password): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => ['token' => $token, 'password' => $password],
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /** @return string the secret, as it would appear in the e-mailed link; issued at the clock's now (mockTime()) */
    private static function issueToken(User $user, PasswordTokenPurpose $purpose): string
    {
        $secret = PasswordTokenSecret::generate();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managedUser = $entityManager->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
        $entityManager->persist(new PasswordToken(Uuid::v7(), $managedUser, $purpose, $secret, Clock::get()->now()));
        $entityManager->flush();

        return $secret->value;
    }

    private static function reloaded(User $user): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
    }
}
