<?php

declare(strict_types=1);

namespace App\Tests\Api\Shared;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 422 violations come back in the request's language, taken from `Accept-Language` (ADR 0028 §5):
 * Symfony's own Polish for its built-in constraints, ours from translations/validators+intl-icu.pl.yaml.
 */
final class ValidationLanguageApiTest extends ApiTestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function acceptLanguages(): iterable
    {
        yield 'Polish' => ['pl', 'Ta wartość nie powinna być pusta.'];
        yield 'Polish with a region and weights' => ['pl-PL,pl;q=0.9,en;q=0.8', 'Ta wartość nie powinna być pusta.'];
        yield 'English' => ['en', 'This value should not be blank.'];
        yield 'an unsupported language falls back to English' => ['de-DE,de;q=0.9', 'This value should not be blank.'];
        yield 'no header falls back to English' => [null, 'This value should not be blank.'];
    }

    #[DataProvider('acceptLanguages')]
    public function test_it_translates_built_in_constraint_messages(?string $acceptLanguage, string $message): void
    {
        $user = UserFactory::createOne();

        $response = static::clientFor($user)->request('POST', '/api/organizations', [
            'json' => ['name' => '', 'countryCode' => 'PL', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw'],
            'headers' => null === $acceptLanguage ? [] : ['Accept-Language' => $acceptLanguage],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['propertyPath' => 'name', 'message' => $message]], array_map(
            static fn (array $violation): array => ['propertyPath' => $violation['propertyPath'], 'message' => $violation['message']],
            $response->toArray(throw: false)['violations'] ?? [],
        ));
    }

    public function test_it_translates_our_own_constraint_messages(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('POST', '/api/payroll-runs', [
            'json' => ['month' => '2026-13'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'month', 'message' => 'To nie jest miesiąc (RRRR-MM).']]]);
    }

    public function test_it_puts_parameters_into_our_own_translated_messages(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('GET', '/api/expenses?from=2026-01-01&to=2027-12-31', [
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'to', 'message' => 'Okres nie może być dłuższy niż 366 dni.']]]);
    }

    public function test_it_keeps_our_own_messages_in_english_for_an_english_request(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('GET', '/api/expenses?from=2026-01-01&to=2027-12-31', [
            'headers' => ['Accept-Language' => 'en'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'to', 'message' => 'The period must not be longer than 366 days.']]]);
    }

    public function test_it_says_which_language_the_response_is_in(): void
    {
        $user = UserFactory::createOne();

        static::clientFor($user)->request('GET', '/api/me', ['headers' => ['Accept-Language' => 'pl']]);

        self::assertResponseHeaderSame('Content-Language', 'pl');
    }
}
