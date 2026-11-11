<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Security;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;

/** Turns a spent rate limit (config/packages/rate_limiter.yaml) into a 429 with Retry-After. */
final class RateLimit
{
    /** @throws TooManyRequestsHttpException when the limiter has no token left */
    public static function consumeOrFail(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many attempts. Try again later.');
        }
    }
}
