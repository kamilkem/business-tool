<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\RevenueEntry;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One line of a daily report's VAT breakdown, as printed, in integer minor units. Whether the rate
 * exists in the organization's country, and VAT 0 at a 0% or exempt rate, the domain checks (422).
 * Properties, not constructor parameters, for the reason given in PaymentLineInput.
 */
final class VatLineInput
{
    /** A rate code of GET /vat-rates: "23", "zw". */
    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    public string $rate;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(RevenueEntry::MAX_AMOUNT)]
    public int $gross;

    /** The VAT in the gross amount, at most the gross amount. */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public int $vat;

    public static function of(string $rate, int $gross, int $vat): self
    {
        $line = new self();
        $line->rate = $rate;
        $line->gross = $gross;
        $line->vat = $vat;

        return $line;
    }

    #[Assert\Callback]
    public function validateVatWithinGross(ExecutionContextInterface $context): void
    {
        if (isset($this->gross, $this->vat) && $this->gross >= 0 && $this->vat > $this->gross) {
            $context->buildViolation('The VAT may not exceed the gross amount.')->atPath('vat')->addViolation();
        }
    }

    /** For Assert\Unique: lines are the same when their rates are. */
    public static function rateOf(mixed $line): mixed
    {
        return $line instanceof self && isset($line->rate) ? $line->rate : $line;
    }
}
