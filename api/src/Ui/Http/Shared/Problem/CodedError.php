<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Problem;

use ApiPlatform\State\ApiResource\Error;
use App\Shared\Domain\Exception\CodedDomainException;

/**
 * API Platform's problem (RFC 9457) with the error code and its parameters (ADR 0028 §5), which
 * `CodedErrorNormalizer` adds to the response: the code of a domain exception, or null for any other
 * error (an unknown route, a failed authorization check, a malformed body). Clients translate the code
 * and fall back to a message for the status; `detail` stays English, for logs and developers.
 */
final class CodedError extends Error
{
    /** @param array<string, string|int|bool|null> $errorParameters */
    private function __construct(
        Error $error,
        public readonly ?string $errorCode,
        public readonly array $errorParameters,
    ) {
        parent::__construct(
            $error->getTitle(),
            $error->getDetail(),
            $error->getStatus(),
            $error->originalTrace,
            $error->getInstance(),
            $error->getType(),
            $error->getHeaders(),
            $error->getPrevious(),
            $error->getMeta(),
            $error->getSource(),
            $error->getDescription(),
        );
    }

    public static function of(Error $error, ?\Throwable $exception): self
    {
        return $exception instanceof CodedDomainException
            ? new self($error, $exception->errorCode(), $exception->errorParameters())
            : new self($error, null, []);
    }
}
