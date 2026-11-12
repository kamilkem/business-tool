<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiProperty;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\RevenueEntry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The gross amount taken with one payment method, in integer minor units of the organization's
 * currency. Properties, not constructor parameters: the serializer then skips a value of the wrong
 * type (a fraction, a string) and reports it as a 422, instead of passing it to a constructor that
 * PHP would coerce it for.
 */
final class PaymentLineInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['cash', 'card', 'transfer', 'voucher']])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'methods'])]
    public string $method;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(RevenueEntry::MAX_AMOUNT)]
    public int $amount;

    public static function of(string $method, int $amount): self
    {
        $line = new self();
        $line->method = $method;
        $line->amount = $amount;

        return $line;
    }

    /** @return list<string> */
    public static function methods(): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases());
    }

    /** For Assert\Unique: lines are the same when their methods are. */
    public static function methodOf(mixed $line): mixed
    {
        return $line instanceof self && isset($line->method) ? $line->method : $line;
    }
}
