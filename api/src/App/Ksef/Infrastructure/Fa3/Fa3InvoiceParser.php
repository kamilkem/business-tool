<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Fa3;

use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\UnsupportedInvoiceFormException;
use App\Ksef\Domain\Fa3InvoiceParserInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Symfony\Component\Intl\Currencies;

/**
 * FA(3) per `schemat_FA(3)_v1-0E.xsd` (https://github.com/CIRFMF/ksef-docs, faktury/schemy/FA) into
 * Expenses' PurchaseInvoiceDetails (ADR 0023 §7, ADR 0024).
 *
 * - Seller: `Podmiot1/DaneIdentyfikacyjne` `NIP` and `Nazwa`. Number `Fa/P_2`, issue date `Fa/P_1`,
 *   currency `Fa/KodWaluty`, kind `Fa/RodzajFaktury`, gross total `Fa/P_15`.
 * - VAT lines by the PL rate codes of VatRates: `P_13_1`/`P_14_1` → 23 (the basic rate, 23% or 22%),
 *   `P_13_2`/`P_14_2` → 8 (8% or 7%), `P_13_3`/`P_14_3` → 5, `P_13_6_1` + `P_13_6_2` + `P_13_6_3` → 0
 *   (domestic, intra-EU supply, export), `P_13_7` → zw. For an invoice not in PLN each line also
 *   carries its VAT in PLN, `P_14_xW` (0 for 0 and zw); when the invoice omits it, the line has none
 *   and Expenses rejects the invoice.
 * - Groups with no PL rate code (`P_13_4`/`P_14_4` taxi flat rate, `P_13_5`/`P_14_5` OSS,
 *   `P_13_8` and `P_13_9` not taxable in Poland, `P_13_10` reverse charge, `P_13_11` margin scheme)
 *   get no line but count in the totals: the net total is the sum of every `P_13_x`, the VAT total of
 *   every `P_14_x` (Expenses stores totals as given, not checked against the lines). The XML is kept,
 *   so the lines can be re-derived if these groups get codes.
 * - Corrections keep their amounts as printed (differences, negative ones included). The corrected
 *   invoice's KSeF number (`DaneFaKorygowanej/NrKSeFFaKorygowanej`) becomes `correctedExternalId`
 *   when the correction names exactly one corrected invoice and it is in KSeF; a correction of several
 *   invoices, or of one issued outside KSeF, has none.
 * - Items (ADR 0040): every `Fa/FaWiersz` in the document's order, as printed: `NrWierszaFa` position,
 *   `P_7` name, `Indeks` seller code, `GTIN`, `P_8A` unit, `P_8B` quantity (`TIlosci`, up to 6
 *   decimals), `P_9A`/`P_9B` unit net/gross price and `P_10` discount (`TKwotowy2`, up to 8 decimals),
 *   the last four as decimal strings; `P_11`/`P_11A` net/gross value and `P_11Vat` VAT in minor units;
 *   `P_12` mapped to the rate codes above (23/22 → 23, 8/7 → 8, 5 → 5, `0 KR`/`0 WDT`/`0 EX` → 0,
 *   zw → zw; 4, 3, oo, np I and np II have no code: null); `StanPrzed` marks a correction's row as it
 *   was before. A row without `P_7` (allowed only in a correction that discounts a whole period) is
 *   skipped, since an item needs a name. An advance invoice (`ZAL`) lists its order in `Zamowienie`
 *   instead and gets no items.
 * - The metadata's amounts are not read.
 */
final readonly class Fa3InvoiceParser implements Fa3InvoiceParserInterface
{
    public const string NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';
    public const string SYSTEM_CODE = 'FA (3)';
    public const string SCHEMA_VERSION = '1-0E';

    /** The currency FA(3)'s `P_14_xW` fields are in: VAT converted under the Polish VAT act. */
    private const string TAX_CURRENCY = 'PLN';

    private const array KINDS = [
        'VAT' => PurchaseInvoiceKind::Regular,
        'KOR' => PurchaseInvoiceKind::Correction,
        'ZAL' => PurchaseInvoiceKind::Advance,
        'ROZ' => PurchaseInvoiceKind::Settlement,
        'UPR' => PurchaseInvoiceKind::Simplified,
        'KOR_ZAL' => PurchaseInvoiceKind::AdvanceCorrection,
        'KOR_ROZ' => PurchaseInvoiceKind::SettlementCorrection,
    ];

    /**
     * Rate code => [net fields, VAT field, VAT-in-PLN field]; null VAT fields for a rate without VAT.
     *
     * @var array<int|string, array{list<string>, ?string, ?string}>
     */
    private const array RATE_GROUPS = [
        '23' => [['P_13_1'], 'P_14_1', 'P_14_1W'],
        '8' => [['P_13_2'], 'P_14_2', 'P_14_2W'],
        '5' => [['P_13_3'], 'P_14_3', 'P_14_3W'],
        '0' => [['P_13_6_1', 'P_13_6_2', 'P_13_6_3'], null, null],
        'zw' => [['P_13_7'], null, null],
    ];

    /** `P_12` (`TStawkaPodatku`) => the rate code of VatRates; a value not here has no code. */
    private const array ITEM_RATES = [
        '23' => '23', '22' => '23',
        '8' => '8', '7' => '8',
        '5' => '5',
        '0 KR' => '0', '0 WDT' => '0', '0 EX' => '0',
        'zw' => 'zw',
    ];

    /** `TIlosci` (quantities) and `TKwotowy2` (unit prices, discounts) of schemat_FA(3)_v1-0E.xsd, anchored. */
    private const string QUANTITY_PATTERN = '/^-?([1-9]\d{0,15}|0)(\.\d{1,6})?$/D';
    private const string PRICE_PATTERN = '/^-?([1-9]\d{0,13}|0)(\.\d{1,8})?$/D';

    /** Every net (`P_13_x`) and VAT (`P_14_x`, invoice currency) field, for the totals. */
    private const array NET_FIELDS = ['P_13_1', 'P_13_2', 'P_13_3', 'P_13_4', 'P_13_5', 'P_13_6_1', 'P_13_6_2', 'P_13_6_3', 'P_13_7', 'P_13_8', 'P_13_9', 'P_13_10', 'P_13_11'];
    private const array VAT_FIELDS = ['P_14_1', 'P_14_2', 'P_14_3', 'P_14_4', 'P_14_5'];

    public function parse(string $xml): PurchaseInvoiceDetails
    {
        $xpath = new \DOMXPath($this->load($xml));
        $xpath->registerNamespace('fa', self::NAMESPACE);

        $this->assertFa3($xpath);

        $currency = strtoupper($this->text($xpath, 'Fa/KodWaluty'));
        if (1 !== preg_match('/^[A-Z]{3}$/D', $currency) || !Currencies::exists($currency)) {
            throw InvalidFa3InvoiceException::invalidValue('Fa/KodWaluty', $currency, 'an ISO 4217 currency code');
        }
        $digits = Currencies::getFractionDigits($currency);
        $inTaxCurrency = self::TAX_CURRENCY === $currency;

        $kindCode = $this->text($xpath, 'Fa/RodzajFaktury');
        $kind = self::KINDS[$kindCode] ?? throw InvalidFa3InvoiceException::invalidValue('Fa/RodzajFaktury', $kindCode, 'an FA(3) invoice kind ('.implode(', ', array_keys(self::KINDS)).')');

        return new PurchaseInvoiceDetails(
            invoiceNumber: $this->text($xpath, 'Fa/P_2'),
            sellerTaxId: $this->text($xpath, 'Podmiot1/DaneIdentyfikacyjne/NIP'),
            sellerName: $this->text($xpath, 'Podmiot1/DaneIdentyfikacyjne/Nazwa'),
            issueDate: $this->date($xpath, 'Fa/P_1'),
            kind: $kind,
            currency: $currency,
            netTotal: $this->sum($xpath, self::NET_FIELDS, $digits),
            vatTotal: $this->sum($xpath, self::VAT_FIELDS, $digits),
            grossTotal: Fa3Amount::toMinorUnits($this->text($xpath, 'Fa/P_15'), $digits, 'Fa/P_15'),
            vatLines: $this->vatLines($xpath, $digits, $inTaxCurrency),
            correctedExternalId: $kind->isCorrection() ? $this->correctedKsefNumber($xpath) : null,
            items: PurchaseInvoiceKind::Advance === $kind ? [] : $this->items($xpath, $digits),
        );
    }

    /**
     * @return list<PurchaseInvoiceItemDetails>
     *
     * @throws InvalidFa3InvoiceException
     */
    private function items(\DOMXPath $xpath, int $digits): array
    {
        $items = [];
        foreach ($this->query($xpath, 'Fa/FaWiersz') as $index => $row) {
            $path = \sprintf('Fa/FaWiersz[%d]', $index + 1);
            $name = $this->childText($xpath, $row, $path, 'P_7');
            if (null === $name) {
                continue;
            }

            $position = $this->childText($xpath, $row, $path, 'NrWierszaFa') ?? throw InvalidFa3InvoiceException::missing($path.'/NrWierszaFa');
            if (1 !== preg_match('/^\d{1,14}$/D', $position) || (int) $position < 1) {
                throw InvalidFa3InvoiceException::invalidValue($path.'/NrWierszaFa', $position, 'a row number (a positive integer)');
            }
            $rate = $this->childText($xpath, $row, $path, 'P_12');

            $items[] = new PurchaseInvoiceItemDetails(
                position: (int) $position,
                name: $name,
                sellerCode: $this->childText($xpath, $row, $path, 'Indeks'),
                gtin: $this->childText($xpath, $row, $path, 'GTIN'),
                unit: $this->childText($xpath, $row, $path, 'P_8A'),
                quantity: $this->childDecimal($xpath, $row, $path, 'P_8B', self::QUANTITY_PATTERN, 'an FA(3) quantity (TIlosci)'),
                unitNetPrice: $this->childDecimal($xpath, $row, $path, 'P_9A', self::PRICE_PATTERN, 'an FA(3) unit price (TKwotowy2)'),
                unitGrossPrice: $this->childDecimal($xpath, $row, $path, 'P_9B', self::PRICE_PATTERN, 'an FA(3) unit price (TKwotowy2)'),
                discount: $this->childDecimal($xpath, $row, $path, 'P_10', self::PRICE_PATTERN, 'an FA(3) discount (TKwotowy2)'),
                netAmount: $this->childAmount($xpath, $row, $path, 'P_11', $digits),
                grossAmount: $this->childAmount($xpath, $row, $path, 'P_11A', $digits),
                vatAmount: $this->childAmount($xpath, $row, $path, 'P_11Vat', $digits),
                vatRate: null === $rate ? null : (self::ITEM_RATES[$rate] ?? null),
                beforeCorrection: '1' === $this->childText($xpath, $row, $path, 'StanPrzed'),
            );
        }

        return $items;
    }

    /**
     * The trimmed text of the child `$name` of a row, or null when there is none or it is empty.
     *
     * @param string $path the row's path, for error messages
     *
     * @throws InvalidFa3InvoiceException when it is repeated
     */
    private function childText(\DOMXPath $xpath, \DOMElement $row, string $path, string $name): ?string
    {
        $nodes = $xpath->query('fa:'.$name, $row) ?: throw new \LogicException(\sprintf('Invalid XPath expression "fa:%s".', $name));
        if ($nodes->length > 1) {
            throw InvalidFa3InvoiceException::repeated($path.'/'.$name);
        }
        $value = trim($nodes->item(0)->textContent ?? '');

        return '' === $value ? null : $value;
    }

    /**
     * A decimal kept as a string (ADR 0040), checked against its FA(3) pattern.
     *
     * @throws InvalidFa3InvoiceException
     */
    private function childDecimal(\DOMXPath $xpath, \DOMElement $row, string $path, string $name, string $pattern, string $expected): ?string
    {
        $value = $this->childText($xpath, $row, $path, $name);
        if (null !== $value && 1 !== preg_match($pattern, $value)) {
            throw InvalidFa3InvoiceException::invalidValue($path.'/'.$name, $value, $expected);
        }

        return $value;
    }

    /** @throws InvalidFa3InvoiceException */
    private function childAmount(\DOMXPath $xpath, \DOMElement $row, string $path, string $name, int $digits): ?int
    {
        $value = $this->childText($xpath, $row, $path, $name);

        return null === $value ? null : Fa3Amount::toMinorUnits($value, $digits, $path.'/'.$name);
    }

    /** @throws InvalidFa3InvoiceException */
    private function load(string $xml): \DOMDocument
    {
        if ('' === trim($xml)) {
            throw InvalidFa3InvoiceException::notXml('the document is empty');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No network, no external entities (PHP 8 never loads them); a DOCTYPE is rejected below,
            // so internal entities cannot expand either.
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
            $error = libxml_get_last_error();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw InvalidFa3InvoiceException::notXml(false !== $error ? trim($error->message) : 'unknown libxml error');
        }
        if (null !== $document->doctype) {
            throw InvalidFa3InvoiceException::doctype();
        }

        return $document;
    }

    /** @throws UnsupportedInvoiceFormException */
    private function assertFa3(\DOMXPath $xpath): void
    {
        $root = $xpath->document->documentElement;
        if (null === $root || 'Faktura' !== $root->localName || self::NAMESPACE !== $root->namespaceURI) {
            throw UnsupportedInvoiceFormException::rootElement((string) $root?->namespaceURI, (string) $root?->localName);
        }

        $formCode = $this->element($xpath, 'Naglowek/KodFormularza');
        $systemCode = $formCode->getAttribute('kodSystemowy');
        $schemaVersion = $formCode->getAttribute('wersjaSchemy');
        if (self::SYSTEM_CODE !== $systemCode || self::SCHEMA_VERSION !== $schemaVersion) {
            throw UnsupportedInvoiceFormException::formCode($systemCode, $schemaVersion);
        }
    }

    /**
     * @param int $digits minor-unit digits of the invoice's currency
     *
     * @return list<PurchaseInvoiceVatAmount>
     *
     * @throws InvalidFa3InvoiceException
     */
    private function vatLines(\DOMXPath $xpath, int $digits, bool $inTaxCurrency): array
    {
        $taxDigits = Currencies::getFractionDigits(self::TAX_CURRENCY);
        $lines = [];
        foreach (self::RATE_GROUPS as $rate => [$netFields, $vatField, $vatInTaxCurrencyField]) {
            $present = array_values(array_filter($netFields, fn (string $field): bool => null !== $this->optionalText($xpath, 'Fa/'.$field)));
            if ([] === $present) {
                continue;
            }

            $vatInTaxCurrency = null;
            if (!$inTaxCurrency) {
                $vatInTaxCurrency = null === $vatInTaxCurrencyField ? 0 : $this->optionalAmount($xpath, $vatInTaxCurrencyField, $taxDigits);
            }

            $lines[] = new PurchaseInvoiceVatAmount(
                rate: (string) $rate,
                net: $this->sum($xpath, $present, $digits),
                vat: null === $vatField ? 0 : $this->amount($xpath, $vatField, $digits),
                vatInTaxCurrency: $vatInTaxCurrency,
            );
        }

        return $lines;
    }

    /**
     * The sum of those of `$fields` that the invoice has; 0 when it has none.
     *
     * @param list<string> $fields under `Fa`
     *
     * @throws InvalidFa3InvoiceException
     */
    private function sum(\DOMXPath $xpath, array $fields, int $digits): int
    {
        $sum = 0;
        foreach ($fields as $field) {
            $amount = $this->optionalAmount($xpath, $field, $digits) ?? 0;
            if ($amount > 0 ? $sum > \PHP_INT_MAX - $amount : $sum < \PHP_INT_MIN - $amount) {
                throw InvalidFa3InvoiceException::invalidValue('Fa/'.$field, (string) $amount, 'an amount whose total fits in 64 bits of minor units');
            }
            $sum += $amount;
        }

        return $sum;
    }

    /** @throws InvalidFa3InvoiceException */
    private function amount(\DOMXPath $xpath, string $field, int $digits): int
    {
        return Fa3Amount::toMinorUnits($this->text($xpath, 'Fa/'.$field), $digits, 'Fa/'.$field);
    }

    /** @throws InvalidFa3InvoiceException */
    private function optionalAmount(\DOMXPath $xpath, string $field, int $digits): ?int
    {
        $value = $this->optionalText($xpath, 'Fa/'.$field);

        return null === $value ? null : Fa3Amount::toMinorUnits($value, $digits, 'Fa/'.$field);
    }

    /** @throws InvalidFa3InvoiceException */
    private function date(\DOMXPath $xpath, string $path): BusinessDate
    {
        $value = $this->text($xpath, $path);
        try {
            return BusinessDate::fromString($value);
        } catch (InvalidBusinessDateException) {
            throw InvalidFa3InvoiceException::invalidValue($path, $value, 'a date (YYYY-MM-DD)');
        }
    }

    private function correctedKsefNumber(\DOMXPath $xpath): ?string
    {
        if (1 !== \count($this->query($xpath, 'Fa/DaneFaKorygowanej'))) {
            return null;
        }
        $ksefNumber = $this->query($xpath, 'Fa/DaneFaKorygowanej/NrKSeFFaKorygowanej');
        $value = 1 === \count($ksefNumber) ? trim($ksefNumber[0]->textContent) : '';

        return '' === $value ? null : $value;
    }

    /**
     * The trimmed text of the one element at `$path`.
     *
     * @throws InvalidFa3InvoiceException when it is missing, repeated or empty
     */
    private function text(\DOMXPath $xpath, string $path): string
    {
        return $this->optionalText($xpath, $path) ?? throw InvalidFa3InvoiceException::missing($path);
    }

    /**
     * The trimmed text of the element at `$path`, or null when there is none or it is empty.
     *
     * @throws InvalidFa3InvoiceException when it is repeated
     */
    private function optionalText(\DOMXPath $xpath, string $path): ?string
    {
        $elements = $this->query($xpath, $path);
        if (\count($elements) > 1) {
            throw InvalidFa3InvoiceException::repeated($path);
        }
        $value = trim($elements[0]->textContent ?? '');

        return '' === $value ? null : $value;
    }

    /** @throws InvalidFa3InvoiceException */
    private function element(\DOMXPath $xpath, string $path): \DOMElement
    {
        $elements = $this->query($xpath, $path);
        if (\count($elements) > 1) {
            throw InvalidFa3InvoiceException::repeated($path);
        }

        return $elements[0] ?? throw InvalidFa3InvoiceException::missing($path);
    }

    /**
     * Elements at a path of FA(3) element names below the root, e.g. `Fa/P_2`.
     *
     * @return list<\DOMElement>
     */
    private function query(\DOMXPath $xpath, string $path): array
    {
        $expression = '/fa:Faktura/'.implode('/', array_map(static fn (string $name): string => 'fa:'.$name, explode('/', $path)));
        $nodes = $xpath->query($expression) ?: throw new \LogicException(\sprintf('Invalid XPath expression "%s".', $expression));

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
