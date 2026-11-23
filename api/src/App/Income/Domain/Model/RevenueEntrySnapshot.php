<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * What a revenue entry held at one moment, for its change history (ADR 0034 §4): the payment lines
 * with each method's name at the time, the total, the VAT lines and the note. Stored as JSON.
 */
final readonly class RevenueEntrySnapshot
{
    /**
     * @param list<array{paymentMethodId: string, paymentMethodName: string, amount: int}> $payments
     * @param list<array{rate: string, gross: int, vat: int}>                               $vatLines
     */
    public function __construct(
        public array $payments,
        public int $total,
        public array $vatLines,
        public ?string $note,
    ) {
    }

    public static function of(RevenueEntry $entry): self
    {
        return new self(
            array_map(static fn (PaymentAmount $line): array => [
                'paymentMethodId' => $line->method->getId()->toRfc4122(),
                'paymentMethodName' => $line->method->getName(),
                'amount' => $line->amount,
            ], $entry->getPaymentLines()),
            $entry->getTotal(),
            array_map(static fn (VatAmount $line): array => ['rate' => $line->rate, 'gross' => $line->gross, 'vat' => $line->vat], $entry->getVatLines()),
            $entry->getNote(),
        );
    }

    /**
     * @param array{payments?: list<array{paymentMethodId: string, paymentMethodName: string, amount: int}>, total?: int, vatLines?: list<array{rate: string, gross: int, vat: int}>, note?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['payments'] ?? [], $data['total'] ?? 0, $data['vatLines'] ?? [], $data['note'] ?? null);
    }

    /** @return array{payments: list<array{paymentMethodId: string, paymentMethodName: string, amount: int}>, total: int, vatLines: list<array{rate: string, gross: int, vat: int}>, note: string|null} */
    public function toArray(): array
    {
        return ['payments' => $this->payments, 'total' => $this->total, 'vatLines' => $this->vatLines, 'note' => $this->note];
    }

    /** Same lines, amounts and note; a renamed method alone is no change. */
    public function sameAs(self $other): bool
    {
        $key = static fn (self $snapshot): array => [
            array_column($snapshot->payments, 'amount', 'paymentMethodId'),
            array_map(static fn (array $line): array => [$line['rate'], $line['gross'], $line['vat']], $snapshot->vatLines),
            $snapshot->note,
        ];
        [$a, $b] = [$key($this), $key($other)];
        ksort($a[0]);
        ksort($b[0]);

        return $a === $b;
    }
}
