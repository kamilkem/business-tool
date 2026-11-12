<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF could not be reached or kept failing: a network error or a 5xx answer that persisted through
 * the retries, an authentication cancelled by the system (status 500 or 550), or an authentication
 * still in progress after the polling budget. A later run may succeed.
 */
final class KsefUnavailableException extends KsefException
{
    public static function afterRetries(string $what, int $attempts, string $lastError): self
    {
        return new self(\sprintf('KSeF %s failed after %d attempts: %s', $what, $attempts, $lastError));
    }

    public static function authenticationNotFinished(int $polls): self
    {
        return new self(\sprintf('KSeF authentication was still in progress after %d status checks.', $polls));
    }

    /** @param list<string> $details */
    public static function authenticationCancelled(int $statusCode, string $description, array $details): self
    {
        return new self(\sprintf('KSeF could not finish the authentication (status %d: %s%s).', $statusCode, $description, [] === $details ? '' : '; '.implode('; ', $details)));
    }
}
