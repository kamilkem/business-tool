<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Infrastructure\Http\KsefApiLimit;
use App\Ksef\Infrastructure\Http\KsefRequestPacer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class KsefRequestPacerTest extends TestCase
{
    private const string NIP = '7251964308';

    private MockClock $clock;
    private ArrayAdapter $cache;
    private KsefRequestPacer $pacer;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-24 19:09:13 UTC');
        $this->cache = new ArrayAdapter();
        $this->pacer = new KsefRequestPacer($this->clock, $this->cache);
    }

    public function test_it_lets_as_many_requests_as_a_second_allows_go_at_once(): void
    {
        $this->paceTimes(8, KsefApiLimit::InvoiceDownload);

        self::assertSame(0.0, $this->elapsed());
    }

    public function test_it_waits_for_the_second_to_pass_after_its_limit(): void
    {
        $this->paceTimes(9, KsefApiLimit::InvoiceDownload);

        self::assertSame(1.25, $this->elapsed());
    }

    public function test_it_waits_for_the_minute_to_pass_after_its_limit(): void
    {
        $this->paceTimes(17, KsefApiLimit::InvoiceDownload);

        self::assertSame(60.25, $this->elapsed());
    }

    public function test_it_uses_each_endpoint_group_s_own_limits(): void
    {
        $this->paceTimes(10, KsefApiLimit::Other);
        self::assertSame(0.0, $this->elapsed(), 'Other endpoints allow 10 a second.');

        $this->paceTimes(17, KsefApiLimit::InvoiceMetadataQuery, 'invoice metadata query');
        self::assertSame(60.25, $this->elapsed(), 'The metadata query allows 8 a second and 16 a minute.');
    }

    public function test_it_ends_the_run_instead_of_waiting_for_the_hour(): void
    {
        $this->paceTimes(20, KsefApiLimit::InvoiceMetadataQuery, 'invoice metadata query');
        $waited = $this->elapsed();

        try {
            $this->pacer->pace(self::NIP, 'invoice metadata query', KsefApiLimit::InvoiceMetadataQuery);
            self::fail('Expected the pacer to stop before the hourly limit.');
        } catch (KsefRateLimitedException $e) {
            self::assertSame((int) ceil(3600.25 - $waited), $e->retryAfterSeconds);
            self::assertSame(\sprintf("Paused before KSeF's limit of 20 invoice metadata query requests per hour; the import continues after %d s.", $e->retryAfterSeconds), $e->getMessage());
            self::assertSame('ksef.rate_limited', $e->errorCode());
        }
        self::assertSame($waited, $this->elapsed(), 'It does not wait for the hour.');

        $this->clock->sleep(3600.25 - $waited);
        $this->pacer->pace(self::NIP, 'invoice metadata query', KsefApiLimit::InvoiceMetadataQuery);
    }

    public function test_it_counts_each_context_and_endpoint_apart(): void
    {
        $this->paceTimes(8, KsefApiLimit::InvoiceDownload);

        $this->pacer->pace('5260250274', 'invoice download', KsefApiLimit::InvoiceDownload);
        $this->pacer->pace(self::NIP, 'session revocation', KsefApiLimit::Other);

        self::assertSame(0.0, $this->elapsed());
    }

    public function test_it_remembers_sent_requests_across_worker_processes(): void
    {
        $this->paceTimes(8, KsefApiLimit::InvoiceDownload);

        (new KsefRequestPacer($this->clock, $this->cache))->pace(self::NIP, 'invoice download', KsefApiLimit::InvoiceDownload);

        self::assertSame(1.25, $this->elapsed());
    }

    public function test_it_forgets_requests_older_than_the_longest_window(): void
    {
        $this->paceTimes(64, KsefApiLimit::InvoiceDownload);
        $this->clock->sleep(3601);
        $before = $this->elapsed();

        $this->paceTimes(8, KsefApiLimit::InvoiceDownload);

        self::assertSame($before, $this->elapsed());
    }

    public function test_it_keeps_waiting_when_a_signal_ends_the_sleep_early(): void
    {
        // The jobs worker's keepalive alarm ends sleep() after at most 5 seconds.
        $interrupted = new class($this->clock) implements ClockInterface {
            public int $sleeps = 0;

            public function __construct(private readonly MockClock $clock)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->clock->now();
            }

            public function sleep(float|int $seconds): void
            {
                ++$this->sleeps;
                $this->clock->sleep(min($seconds, 4.0));
            }

            public function withTimeZone(\DateTimeZone|string $timezone): static
            {
                return $this;
            }
        };
        $pacer = new KsefRequestPacer($interrupted, $this->cache);

        for ($request = 1; $request <= 17; ++$request) {
            $pacer->pace(self::NIP, 'invoice download', KsefApiLimit::InvoiceDownload);
        }

        self::assertSame(60.25, $this->elapsed(), 'The 17th request still waits for the minute to pass.');
        self::assertGreaterThan(2, $interrupted->sleeps);
    }

    private function paceTimes(int $times, KsefApiLimit $limit, string $endpoint = 'invoice download'): void
    {
        for ($request = 1; $request <= $times; ++$request) {
            $this->pacer->pace(self::NIP, $endpoint, $limit);
        }
    }

    private function elapsed(): float
    {
        return round((float) $this->clock->now()->format('U.u') - (float) (new \DateTimeImmutable('2026-11-24 19:09:13 UTC'))->format('U.u'), 6);
    }
}
