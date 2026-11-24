<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefRateLimitedException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Keeps the requests to each KSeF endpoint within its API limits (KsefApiLimit), so that the client
 * meets a limit here instead of in a 429: KSeF blocks further requests after a breach, and repeated
 * breaches lengthen the block (ADR 0023 §5).
 *
 * Before each request it waits until one more fits every window, when that takes at most
 * MAX_WAIT_SECONDS (the per-second and per-minute limits); a longer wait (the per-hour limit) throws
 * KsefRateLimitedException without calling KSeF, which ends the run like a 429 and lets the next run
 * continue. The times of sent requests are kept in the app cache per context and endpoint, so they
 * outlive the worker process, which restarts every hour.
 */
final readonly class KsefRequestPacer
{
    /** Added to every wait: KSeF counts requests when they arrive, the pacer when they leave. */
    public const float MARGIN_SECONDS = 0.25;
    /** Waits up to this long; a longer one ends the run. */
    public const int MAX_WAIT_SECONDS = 90;

    public function __construct(
        private ClockInterface $clock,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Waits until one more request to `$endpoint` for `$context` fits `$limit`, and records it.
     *
     * @throws KsefRateLimitedException when that would take longer than MAX_WAIT_SECONDS
     */
    public function pace(string $context, string $endpoint, KsefApiLimit $limit): void
    {
        $item = $this->cache->getItem('ksef_requests_'.hash('xxh128', $context."\0".$endpoint));
        $now = $this->now();
        $sent = self::sentWithin($item->get(), $now - $limit->longestWindowSeconds());

        $wait = 0.0;
        $binding = null;
        foreach ($limit->perWindow() as $window => $allowed) {
            $inWindow = array_values(array_filter($sent, static fn (float $at): bool => $at > $now - $window));
            if (\count($inWindow) < $allowed) {
                continue;
            }
            // The request may leave once enough of the earliest ones have left the window.
            $windowWait = $inWindow[\count($inWindow) - $allowed] + $window - $now + self::MARGIN_SECONDS;
            if ($windowWait > $wait) {
                $wait = $windowWait;
                $binding = [$allowed, $window];
            }
        }

        if (null !== $binding && $wait > self::MAX_WAIT_SECONDS) {
            throw KsefRateLimitedException::pausedBeforeLimit((int) ceil($wait), $endpoint, $binding[0], $binding[1]);
        }
        // A signal ends sleep() early (the jobs worker's --keepalive alarm fires every 5 s), so it
        // sleeps again until the moment is reached.
        $until = $now + $wait;
        while (($left = $until - $now) > 0) {
            $this->clock->sleep($left);
            $now = $this->now();
        }

        $sent[] = $now;
        $this->cache->save($item->set($sent)->expiresAfter($limit->longestWindowSeconds()));
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /** @return list<float> the recorded send times after `$since`, oldest first */
    private static function sentWithin(mixed $recorded, float $since): array
    {
        if (!\is_array($recorded)) {
            return [];
        }
        $sent = array_values(array_filter($recorded, static fn (mixed $at): bool => \is_float($at) && $at > $since));
        sort($sent);

        return $sent;
    }
}
