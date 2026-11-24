<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF answered 429 (a limit for the pair (context, client IP) is exceeded), or the client paused
 * before reaching one. It is never retried within the run: repeated breaches lengthen the block. The
 * run ends and records `retryAfterSeconds` (ADR 0023 §5).
 */
final class KsefRateLimitedException extends KsefException
{
    public function __construct(public readonly ?int $retryAfterSeconds, string $detail, ?string $message = null)
    {
        parent::__construct($message ?? \sprintf(
            'KSeF rate limit exceeded%s%s',
            null === $retryAfterSeconds ? '' : \sprintf(', retry after %d s', $retryAfterSeconds),
            '' === $detail ? '.' : ': '.$detail,
        ), ['retryAfterSeconds' => $retryAfterSeconds]);
    }

    /** The client stopped before KSeF's own limit for an endpoint, without sending the request. */
    public static function pausedBeforeLimit(int $retryAfterSeconds, string $endpoint, int $allowed, int $windowSeconds): self
    {
        return new self($retryAfterSeconds, '', \sprintf(
            'Paused before KSeF\'s limit of %d %s requests per %s; the import continues after %d s.',
            $allowed,
            $endpoint,
            match ($windowSeconds) {
                1 => 'second',
                60 => 'minute',
                3600 => 'hour',
                default => $windowSeconds.' seconds',
            },
            $retryAfterSeconds,
        ));
    }
}
