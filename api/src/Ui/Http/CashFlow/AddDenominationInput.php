<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use Symfony\Component\Validator\Constraints as Assert;

/** The currency is the organization's; uniqueness of the value in it is the domain's (409). */
final readonly class AddDenominationInput
{
    public function __construct(
        /** Face value in minor units of the organization's currency (500 for 5 zł). */
        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: Denomination::MAX_VALUE)]
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'kinds'])]
        public string $kind,
    ) {
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (DenominationKind $kind): string => $kind->value, DenominationKind::cases());
    }
}
