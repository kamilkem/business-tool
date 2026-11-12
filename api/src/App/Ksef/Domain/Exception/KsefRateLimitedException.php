<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF answered 429: a limit for the pair (context, client IP) is exceeded. It is never retried within
 * the run: repeated breaches lengthen the block. The run ends and records `retryAfterSeconds`
 * (ADR 0023 §5).
 */
final class KsefRateLimitedException extends KsefException
{
    public function __construct(public readonly ?int $retryAfterSeconds, string $detail)
    {
        parent::__construct(\sprintf(
            'KSeF rate limit exceeded%s%s',
            null === $retryAfterSeconds ? '' : \sprintf(', retry after %d s', $retryAfterSeconds),
            '' === $detail ? '.' : ': '.$detail,
        ));
    }
}
