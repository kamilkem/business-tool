<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Exception;

/** Only owners and managers read the business summary (ADR 0029 §3, ADR 0021). */
final class BusinessSummaryForbiddenException extends ReportingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not read the business summary: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
