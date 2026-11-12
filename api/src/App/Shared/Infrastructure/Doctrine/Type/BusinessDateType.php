<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * Stores a BusinessDate in a DATE column (ADR 0017, Consequences): a calendar date, with no time and
 * no time zone, in and out. Map a property with `#[ORM\Column(type: 'business_date')]`.
 */
final class BusinessDateType extends Type
{
    public const string NAME = 'business_date';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof BusinessDate) {
            return $value->toString();
        }

        throw InvalidType::new($value, self::NAME, ['null', BusinessDate::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BusinessDate
    {
        if (null === $value || $value instanceof BusinessDate) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', BusinessDate::class]);
        }

        try {
            return BusinessDate::fromString($value);
        } catch (InvalidBusinessDateException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }
}
