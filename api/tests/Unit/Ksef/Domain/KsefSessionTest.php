<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Model\KsefFormCode;
use App\Ksef\Domain\Model\KsefSession;
use PHPUnit\Framework\TestCase;

final class KsefSessionTest extends TestCase
{
    public function test_it_replaces_the_access_token_on_refresh(): void
    {
        $session = self::session();

        $session->replaceAccessToken('fixture-refreshed-access-token', new \DateTimeImmutable('2026-11-12 23:12:49 UTC'));

        self::assertSame('fixture-refreshed-access-token', $session->accessToken());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:12:49 UTC'), $session->accessTokenValidUntil());
        self::assertSame('fixture-refresh-token', $session->refreshToken());
    }

    public function test_it_is_never_serialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(self::session());
    }

    public function test_it_keeps_its_tokens_out_of_dumps(): void
    {
        $dump = print_r(self::session(), true);

        self::assertStringNotContainsString('fixture-access-token', $dump);
        self::assertStringNotContainsString('fixture-refresh-token', $dump);
        self::assertStringContainsString('7251964308', $dump);
    }

    public function test_it_recognises_the_fa3_form_code(): void
    {
        self::assertTrue(new KsefFormCode('FA (3)', '1-0E', 'FA')->isFa3());
        self::assertFalse(new KsefFormCode('FA (2)', '1-0E', 'FA')->isFa3());
        self::assertFalse(new KsefFormCode('PEF (3)', '2-1', 'PEF')->isFa3());
    }

    private static function session(): KsefSession
    {
        return new KsefSession(
            '7251964308',
            '20261112-AU-2C9EA54000-C4E1F6E27B-6F',
            'fixture-access-token',
            new \DateTimeImmutable('2026-11-12 22:57:49 UTC'),
            'fixture-refresh-token',
            new \DateTimeImmutable('2026-11-19 22:42:49 UTC'),
        );
    }
}
