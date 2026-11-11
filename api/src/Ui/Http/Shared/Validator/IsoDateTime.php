<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A moment sent by a client: an ISO 8601 / RFC 3339 date-time with seconds and a time zone offset
 * (`2026-11-12T16:00:00Z`, `2026-11-12T17:00:00+01:00`, or with a fraction of a second, as
 * JavaScript's `toISOString()` sends it). Inputs take moments as strings with this constraint
 * because the serializer's date-time denormalizer accepts anything PHP can parse, `noon` and
 * `yesterday` included, and rolls 30 February over into March. A null value is valid.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class IsoDateTime extends Assert\Compound
{
    public const string PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D';
    public const string MESSAGE = 'This value is not a valid date-time with a time zone offset, e.g. 2026-11-12T16:00:00+01:00.';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Type('string'),
            new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                if (!\is_string($value)) {
                    return;
                }

                if (!self::isValid($value)) {
                    $context->buildViolation(self::MESSAGE)->addViolation();
                }
            }),
        ];
    }

    private static function isValid(string $value): bool
    {
        if (1 !== preg_match(self::PATTERN, $value, $parts)) {
            return false;
        }

        // A real calendar date and time of day: no 2026-02-30 or 24:00:00.
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $parts[1]);
        $offset = 'Z' === $parts[3] ? '+00:00' : $parts[3];

        return false !== $local
            && $local->format('Y-m-d\TH:i:s') === $parts[1]
            && (int) substr($offset, 1, 2) <= 14 && (int) substr($offset, 4, 2) < 60;
    }
}
