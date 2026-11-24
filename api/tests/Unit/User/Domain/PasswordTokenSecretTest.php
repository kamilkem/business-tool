<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Domain;

use App\User\Domain\Model\PasswordTokenSecret;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordTokenSecret::class)]
final class PasswordTokenSecretTest extends TestCase
{
    public function test_it_takes_back_a_generated_secret_with_the_same_hash(): void
    {
        $generated = PasswordTokenSecret::generate();

        $again = PasswordTokenSecret::fromValue($generated->value);

        self::assertSame($generated->value, $again->value);
        self::assertSame($generated->hash(), $again->hash());
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 42)];
        yield 'too long' => [str_repeat('a', 44)];
        yield 'not base64url' => [str_repeat('a', 42).'+'];
    }

    #[DataProvider('malformed')]
    public function test_it_refuses_a_value_that_generate_could_not_have_written(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordTokenSecret::fromValue($value);
    }
}
