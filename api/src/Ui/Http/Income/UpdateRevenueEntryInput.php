<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueEntry\RevenuePaymentLineView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateRevenueEntryInputProvider pre-fills it from the
 * current entry and API Platform applies the request body onto it, so fields the client did not send
 * keep their value. A list that is sent replaces the current one whole (JSON merge patch replaces
 * arrays); a null `vatLines` removes the breakdown, as `[]` does.
 */
final class UpdateRevenueEntryInput
{
    public const int NOTE_MAX_LENGTH = 1000;

    /** @var list<PaymentLineInput>|null at least one line, each payment method once */
    #[Assert\NotNull]
    #[Assert\Count(min: 1)]
    #[Assert\Unique(normalizer: [PaymentLineInput::class, 'methodOf'], message: 'Each payment method may appear only once.')]
    #[Assert\Valid]
    public ?array $payments = null;

    /** @var list<VatLineInput>|null the VAT breakdown as printed, each rate once; empty or null removes it */
    #[Assert\Unique(normalizer: [VatLineInput::class, 'rateOf'], message: 'Each VAT rate may appear only once.')]
    #[Assert\Valid]
    public ?array $vatLines = null;

    /** An empty note removes it. */
    #[Assert\Length(max: self::NOTE_MAX_LENGTH, normalizer: 'trim')]
    public ?string $note = null;

    public static function fromView(RevenueEntryView $view): self
    {
        $input = new self();
        $input->payments = array_map(static fn (RevenuePaymentLineView $line): PaymentLineInput => PaymentLineInput::of($line->method, $line->amount), $view->payments);
        $input->vatLines = array_map(static fn (RevenueVatLineView $line): VatLineInput => VatLineInput::of($line->rate, $line->gross, $line->vat), $view->vatLines);
        $input->note = $view->note;

        return $input;
    }
}
