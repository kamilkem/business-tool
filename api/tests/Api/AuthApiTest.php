<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Factory\UserFactory;

final class AuthApiTest extends ApiTestCase
{
    public function test_it_returns_a_token_for_valid_credentials(): void
    {
        UserFactory::createOne(['email' => 'jane@example.com']);

        $response = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'jane@example.com', 'password' => UserFactory::PASSWORD],
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $response->toArray());
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        UserFactory::createOne(['email' => 'jane@example.com']);

        static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'jane@example.com', 'password' => 'nope'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_requires_authentication_for_the_api(): void
    {
        static::createClient()->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }
}
