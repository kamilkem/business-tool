<?php

declare(strict_types=1);

namespace Ui\Fixture;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\DefaultCashStorages;
use App\CashFlow\Domain\Model\DefaultDenominations;
use App\CashFlow\Domain\Model\Denomination;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\DefaultPaymentMethods;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Ksef\Domain\Model\KsefConnection;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Model\LockedPayrollDates;
use App\Purchasing\Domain\Model\DefaultProductCategories;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Domain\SecretCipherInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\User\Domain\Model\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Local development data. All demo accounts use the password "password".
 *
 *   owner@example.com     owner of "Demo Bar"
 *   manager@example.com   manager of "Demo Bar"
 *   employee@example.com  employee of "Demo Bar"
 *   mixed@example.com     owner of "Demo Café" and employee of "Demo Bar" (the admin follows the selected organization's role)
 *
 * "Demo Bar" has the registers "Bar" and "Kitchen" with an entry for each of the last 31 days
 * (the kitchen is closed on Mondays), and a deactivated register "Terrace". Both organizations
 * have the default product categories and payment methods of their country, as a new organization
 * gets them; "Demo Bar" also has a "BLIK" payment method, used on some days.
 *
 * "Demo Bar" (NIP 526-025-09-95) has a KSeF connection with a dev-only token whose last import
 * succeeded, and purchase invoices of this month and the last two: imported from KSeF (with their
 * XML, a correction with negative amounts and one in EUR) and entered by hand.
 *
 * For payroll, Eve Employee and Mia Mixed have hourly rates from the first day of the month before
 * last (Eve also a day rate on the 31st of last month), Max Manager has none, and the three have
 * ended shifts last month and this month (evening shifts, some past midnight). No payroll run
 * exists: generate last month's on the Payroll page. In dev, KSEF_API_URL in a git-ignored `.env.dev.local` should point at ksef.invalid,
 * so "Import now" never reaches KSeF.
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_PASSWORD = 'password';

    private const string DEMO_BAR_NIP = '5260250995';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        private readonly TenantContextInterface $tenantContext,
        private readonly SecretCipherInterface $cipher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $owner = $this->user($manager, 'owner@example.com', 'Olivia', 'Owner', $now);
        $bar = new Organization(Uuid::v7(), 'Demo Bar', $owner, $now, self::DEMO_BAR_NIP);
        $barManager = $this->user($manager, 'manager@example.com', 'Max', 'Manager', $now);
        $bar->addMember($owner->getId(), $barManager, MembershipRole::Manager, $now);
        $employee = $this->user($manager, 'employee@example.com', 'Eve', 'Employee', $now);
        $bar->addMember($owner->getId(), $employee, MembershipRole::Employee, $now);
        $manager->persist($bar);

        $mixed = $this->user($manager, 'mixed@example.com', 'Mia', 'Mixed', $now);
        $bar->addMember($owner->getId(), $mixed, MembershipRole::Employee, $now);
        $cafe = new Organization(Uuid::v7(), 'Demo Café', $mixed, $now);
        $manager->persist($cafe);

        $manager->flush();

        $this->income($manager, $bar, $owner->getId(), $now);
        $manager->flush();
        $this->paymentMethods($manager, $cafe, $mixed->getId(), $now);
        $manager->flush();

        $this->productCategories($manager, $bar, $owner->getId(), $now);
        $this->productCategories($manager, $cafe, $mixed->getId(), $now);

        $this->denominations($manager, $bar, $owner->getId(), $now);
        $this->denominations($manager, $cafe, $mixed->getId(), $now);
        $manager->flush();
        $this->cashStorages($manager, $bar, $owner->getId(), $now);
        $this->cashStorages($manager, $cafe, $mixed->getId(), $now);
        $manager->flush();

        $this->ksefConnection($manager, $bar, $owner->getId(), $now);
        $this->purchaseInvoices($manager, $bar, $owner->getId(), $now);

        $this->payroll($manager, $bar, $owner->getId(), [$employee->getId(), $mixed->getId(), $barManager->getId()], $now);
        $manager->flush();
    }

    /**
     * Rates and ended shifts for payroll (ADR 0027): the first two people have an hourly rate, the
     * third none, so last month's run flags their shifts until a rate is set.
     *
     * @param list<Uuid> $people
     */
    private function payroll(ObjectManager $manager, Organization $organization, Uuid $ownerId, array $people, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        $timeZone = new \DateTimeZone($organization->getTimezone());
        $today = new \DateTimeImmutable(BusinessDate::containing($now, $timeZone)->toString(), $timeZone);
        $lastMonth = $today->modify('first day of last month');
        $rateFrom = BusinessDate::fromString($today->modify('first day of -2 month')->format('Y-m-d'));

        foreach (array_slice($people, 0, 2) as $index => $userId) {
            $manager->persist(HourlyRate::set(Uuid::v7(), HourlyRateHistory::of($userId, []), $rateFrom, [3200, 3000][$index], $organization->getCurrency(), LockedPayrollDates::none(), $ownerId, MembershipRole::Owner, $now));
        }
        $newYearsEveLike = BusinessDate::fromString($lastMonth->modify('last day of this month')->format('Y-m-d'));
        $manager->persist(DayRate::set(Uuid::v7(), $people[0], $newYearsEveLike, 4500, $organization->getCurrency(), null, LockedPayrollDates::none(), $ownerId, MembershipRole::Owner, $now));

        // Days of the month (last month, then this month up to yesterday), start "H:i" and length in minutes.
        $schedule = [
            [[3, '18:00', 480], [7, '16:00', 360], [10, '20:00', 420], [14, '17:30', 390], [17, '18:00', 480], [21, '12:00', 300], [24, '19:00', 465], [31, '20:00', 420]],
            [[5, '17:00', 360], [12, '18:00', 420], [19, '16:30', 330], [26, '18:00', 480], [28, '10:00', 240]],
            [[2, '09:00', 480], [9, '09:00', 450], [16, '09:00', 480], [23, '09:00', 510]],
        ];
        foreach ($schedule as $index => $shifts) {
            foreach ([$lastMonth, $today->modify('first day of this month')] as $month) {
                foreach ($shifts as [$day, $start, $minutes]) {
                    if ($day > (int) $month->format('t')) {
                        continue;
                    }
                    $startedAt = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->modify($start);
                    $endedAt = $startedAt->modify(\sprintf('+%d minutes', $minutes));
                    if ($endedAt >= $today) {
                        continue;
                    }
                    $manager->persist(Shift::record(Uuid::v7(), $people[$index], $startedAt, $endedAt, null, $ownerId, MembershipRole::Owner, [], $now, ShiftPeriodLocks::none()));
                }
            }
        }
    }

    /**
     * The defaults of the organization's country (ADR 0030), as AddDefaultPaymentMethodsOnOrganizationCreated
     * adds them, then the extra names.
     *
     * @param list<string> $extra
     *
     * @return list<PaymentMethod> cash, card, transfer, voucher, then the extra ones
     */
    private function paymentMethods(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now, array $extra = []): array
    {
        $this->tenantContext->switchTo($organization);
        $methods = [];
        $extra = array_map(static fn (string $name): array => ['name' => $name, 'cash' => false], $extra);
        foreach ([...DefaultPaymentMethods::forCountry($organization->getCountryCode()), ...$extra] as $position => $default) {
            $methods[] = $method = PaymentMethod::create(Uuid::v7(), $default['name'], null, $position, $default['cash'], null, $ownerId, MembershipRole::Owner, $now);
            $manager->persist($method);
        }

        return $methods;
    }

    /** The defaults of the organization's currency (ADR 0033 §2), as AddDefaultDenominationsOnOrganizationCreated adds them. */
    private function denominations(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        foreach (DefaultDenominations::forCurrency($organization->getCurrency()) as $default) {
            $manager->persist(Denomination::create(Uuid::v7(), $organization->getCurrency(), $default['value'], $default['kind'], null, $ownerId, MembershipRole::Owner, $now));
        }
    }

    /** The defaults of the organization's country (ADR 0033 §3), as AddDefaultCashStoragesOnOrganizationCreated adds them. */
    private function cashStorages(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        foreach (DefaultCashStorages::forCountry($organization->getCountryCode()) as $default) {
            $manager->persist(CashStorage::create(Uuid::v7(), $default['name'], $default['mode'], null, $ownerId, MembershipRole::Owner, $now));
        }
    }

    /** The default tree of the organization's country (#238), as AddDefaultProductCategoriesOnOrganizationCreated adds it. */
    private function productCategories(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        foreach (DefaultProductCategories::forCountry($organization->getCountryCode()) as $default) {
            $top = ProductCategory::create(Uuid::v7(), $default['name'], null, null, $ownerId, MembershipRole::Owner, $now);
            $manager->persist($top);
            foreach ($default['children'] as $name) {
                $manager->persist(ProductCategory::create(Uuid::v7(), $name, $top, null, $ownerId, MembershipRole::Owner, $now));
            }
        }
        $manager->flush();
    }

    /**
     * A KSeF connection as an owner saves it (#49): the token is an App\Secret secret encrypted
     * with the dev keyring, as SetOrganizationSecretHandler stores it. The last nightly import ran at 04:00 today and
     * imported the invoices below.
     */
    private function ksefConnection(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        $secretId = Uuid::v7();
        $kind = SecretKind::KsefToken;
        $token = $this->cipher->encrypt('dev-only-ksef-token', OrganizationSecret::associatedData($organization->getId(), $secretId, $kind));
        $manager->persist(OrganizationSecret::create($secretId, $kind, $token, $ownerId, MembershipRole::Owner, $now));

        $timeZone = new \DateTimeZone($organization->getTimezone());
        $today = BusinessDate::containing($now, $timeZone);
        $connection = KsefConnection::connect(Uuid::v7(), $secretId, $organization->getCountryCode(), $organization->getTaxId(), $ownerId, MembershipRole::Owner, $today->previous()->previous()->startsAt($timeZone));
        $lastRun = $today->startsAt($timeZone)->modify('+4 hours');
        if ($lastRun > $now) {
            $lastRun = $lastRun->modify('-1 day');
        }
        $connection->recordCheck($lastRun->modify('-2 days'));
        $connection->advanceSyncCursor($lastRun);
        $connection->recordSyncRun($lastRun, 5, 0);
        $manager->persist($connection);
        $manager->flush();
    }

    /**
     * Purchase invoices of this month and the two before (#52, #54). Amounts are in grosze; the
     * KSeF ones carry a small FA(3)-shaped XML as their document.
     */
    private function purchaseInvoices(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        $today = BusinessDate::containing($now, new \DateTimeZone($organization->getTimezone()));
        $daysAgo = static function (int $days) use ($today): BusinessDate {
            $date = $today;
            for ($i = 0; $i < $days; ++$i) {
                $date = $date->previous();
            }

            return $date;
        };
        $country = $organization->getCountryCode();
        $currency = $organization->getCurrency();
        $wholesaler = ['5213000042', 'Hurtownia Napojów Demo Sp. z o.o.'];
        $butcher = ['6762468312', 'Zakład Mięsny Demo'];
        $landlord = ['5272738219', 'Demo Nieruchomości S.A.'];

        $imported = [
            // [days ago, number, seller, kind, currency, lines [rate, net, vat, vatInTaxCurrency], corrected]
            [2, 'FV/HN/0412', $wholesaler, PurchaseInvoiceKind::Regular, 'PLN', [['23', 184_500, 42_435, null], ['8', 22_000, 1_760, null]], null],
            [5, 'FV/HN/0398', $wholesaler, PurchaseInvoiceKind::Regular, 'PLN', [['23', 312_040, 71_769, null]], null],
            [6, 'KOR/HN/0021', $wholesaler, PurchaseInvoiceKind::Correction, 'PLN', [['23', -12_000, -2_760, null]], 'DEMO-KSEF-FV-HN-0398'],
            [9, '118/MS/2026', $butcher, PurchaseInvoiceKind::Regular, 'PLN', [['5', 96_380, 4_819, null]], null],
            [12, 'FA/2026/77', $landlord, PurchaseInvoiceKind::Regular, 'PLN', [['23', 650_000, 149_500, null]], null],
            [10, 'INV-DE-5521', ['DE811234567', 'Weinhandel Demo GmbH'], PurchaseInvoiceKind::Regular, 'EUR', [['23', 48_000, 11_040, 47_472]], null],
            [36, 'FV/HN/0351', $wholesaler, PurchaseInvoiceKind::Regular, 'PLN', [['23', 278_900, 64_147, null], ['8', 18_500, 1_480, null]], null],
            [44, 'FA/2026/71', $landlord, PurchaseInvoiceKind::Regular, 'PLN', [['23', 650_000, 149_500, null]], null],
            [61, '097/MS/2026', $butcher, PurchaseInvoiceKind::Regular, 'PLN', [['5', 88_210, 4_411, null]], null],
        ];
        // The items as printed (ADR 0040), by invoice number; the others have none, as invoices
        // imported before items were kept. Quantities and unit prices are decimal strings.
        $items = [
            // Net-based: no gross value per row.
            'FV/HN/0412' => [
                new PurchaseInvoiceItemDetails(1, 'Piwo jasne Demo KEG 30 l', sellerCode: 'HN-KEG30-J', unit: 'szt.', quantity: '2', unitNetPrice: '389.50', netAmount: 77_900, vatRate: '23'),
                new PurchaseInvoiceItemDetails(2, 'Wódka Demo 0,7 l', sellerCode: 'HN-WD07', gtin: '5900000000017', unit: 'szt.', quantity: '12', unitNetPrice: '41.25', netAmount: 49_500, vatRate: '23'),
                new PurchaseInvoiceItemDetails(3, 'Tonik Demo 0,2 l', gtin: '5900000000024', unit: 'szt.', quantity: '144', unitNetPrice: '0.39583333', netAmount: 5_700, vatRate: '23'),
                new PurchaseInvoiceItemDetails(4, 'Syrop cukrowy 1 l', unit: 'szt.', quantity: '20', unitNetPrice: '25.70', netAmount: 51_400, vatRate: '23'),
                new PurchaseInvoiceItemDetails(5, 'Woda mineralna Demo 0,33 l', gtin: '5900000000031', unit: 'szt.', quantity: '240', unitNetPrice: '0.91666667', netAmount: 22_000, vatRate: '8'),
            ],
            'FV/HN/0398' => [
                new PurchaseInvoiceItemDetails(1, 'Piwo jasne Demo KEG 30 l', sellerCode: 'HN-KEG30-J', unit: 'szt.', quantity: '8', unitNetPrice: '390.05', netAmount: 312_040, vatRate: '23'),
            ],
            // A correction: the row before it and the row after.
            'KOR/HN/0021' => [
                new PurchaseInvoiceItemDetails(1, 'Piwo jasne Demo KEG 30 l', sellerCode: 'HN-KEG30-J', unit: 'szt.', quantity: '8', unitNetPrice: '390.05', netAmount: 312_040, vatRate: '23', beforeCorrection: true),
                new PurchaseInvoiceItemDetails(1, 'Piwo jasne Demo KEG 30 l', sellerCode: 'HN-KEG30-J', unit: 'szt.', quantity: '8', unitNetPrice: '375.05', netAmount: 300_040, vatRate: '23'),
            ],
            // Gross-based (FA(3) P_9B, P_11A): no net value per row.
            '118/MS/2026' => [
                new PurchaseInvoiceItemDetails(1, 'Schab wieprzowy b/k', unit: 'kg', quantity: '12.350', unitGrossPrice: '38.99', grossAmount: 48_153, vatRate: '5'),
                new PurchaseInvoiceItemDetails(2, 'Boczek surowy', unit: 'kg', quantity: '8.2', unitGrossPrice: '29.49', grossAmount: 24_182, vatRate: '5'),
                new PurchaseInvoiceItemDetails(3, 'Kiełbasa śląska', unit: 'kg', quantity: '10', unitGrossPrice: '28.864', grossAmount: 28_864, vatRate: '5'),
            ],
            'FA/2026/77' => [
                new PurchaseInvoiceItemDetails(1, 'Czynsz najmu lokalu, ul. Demo 1', unit: 'mies.', quantity: '1', unitNetPrice: '6500', unitGrossPrice: '7995', netAmount: 650_000, grossAmount: 799_500, vatAmount: 149_500, vatRate: '23'),
            ],
        ];
        foreach ($imported as [$days, $number, [$taxId, $name], $kind, $invoiceCurrency, $lines, $corrected]) {
            $details = self::invoiceDetails($number, $taxId, $name, $daysAgo($days), $kind, $invoiceCurrency, $lines, $corrected, $items[$number] ?? []);
            $externalId = 'DEMO-KSEF-'.strtoupper((string) preg_replace('/\W+/', '-', $number));
            $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, $externalId, $details, $country, $currency, $now);
            $manager->persist($invoice);
            $manager->persist(PurchaseInvoiceDocument::of($invoice, self::fa3Xml($details, self::DEMO_BAR_NIP), 'FA (3)', $now));
        }

        $manual = [
            // [days ago, number, seller, lines]
            [3, null, [null, 'Sklep Demo (paragon)'], [['23', 4_065, 935, null], ['5', 2_381, 119, null]]],
            [15, 'R/2026/311', ['9512345674', 'Serwis Ekspresów Demo'], [['23', 35_000, 8_050, null]]],
            [40, null, [null, 'Drukarnia Demo (paragon)'], [['23', 12_195, 2_805, null]]],
        ];
        foreach ($manual as [$days, $number, [$taxId, $name], $lines]) {
            $invoice = PurchaseInvoice::enter(Uuid::v7(), self::invoiceDetails($number, $taxId, $name, $daysAgo($days), PurchaseInvoiceKind::Regular, $currency, $lines, null), $country, $currency, $ownerId, MembershipRole::Owner, $now);
            $manager->persist($invoice);
        }
        $manager->flush();
    }

    /**
     * @param list<array{string, int, int, ?int}> $lines [rate, net, vat, vat in the tax currency]
     * @param list<PurchaseInvoiceItemDetails>    $items the items as printed, for an imported invoice
     */
    private static function invoiceDetails(?string $number, ?string $taxId, string $name, BusinessDate $issueDate, PurchaseInvoiceKind $kind, string $currency, array $lines, ?string $corrected, array $items = []): PurchaseInvoiceDetails
    {
        $vatLines = array_map(static fn (array $line): PurchaseInvoiceVatAmount => new PurchaseInvoiceVatAmount(...$line), $lines);
        $net = array_sum(array_column($lines, 1));
        $vat = array_sum(array_column($lines, 2));

        return new PurchaseInvoiceDetails($number, $taxId, $name, $issueDate, $kind, $currency, $net, $vat, $net + $vat, $vatLines, $corrected, $items);
    }

    /** A short FA(3)-shaped document: enough to download and read, not a complete invoice. */
    private static function fa3Xml(PurchaseInvoiceDetails $details, string $buyerNip): string
    {
        $amount = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        $rows = '';
        foreach ($details->vatLines as $index => $line) {
            $rows .= \sprintf("    <P_13_%1\$d>%2\$s</P_13_%1\$d>\n    <P_14_%1\$d>%3\$s</P_14_%1\$d>\n", $index + 1, $amount($line->net), $amount($line->vat));
        }

        return \sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
                  <Naglowek>
                    <KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza>
                    <WariantFormularza>3</WariantFormularza>
                  </Naglowek>
                  <Podmiot1><DaneIdentyfikacyjne><NIP>%s</NIP><Nazwa>%s</Nazwa></DaneIdentyfikacyjne></Podmiot1>
                  <Podmiot2><DaneIdentyfikacyjne><NIP>%s</NIP><Nazwa>Demo Bar</Nazwa></DaneIdentyfikacyjne></Podmiot2>
                  <Fa>
                    <KodWaluty>%s</KodWaluty>
                    <P_1>%s</P_1>
                    <P_2>%s</P_2>
                %s    <P_15>%s</P_15>
                    <RodzajFaktury>%s</RodzajFaktury>
                  </Fa>
                </Faktura>

                XML,
            htmlspecialchars((string) $details->sellerTaxId, \ENT_XML1),
            htmlspecialchars($details->sellerName, \ENT_XML1),
            $buyerNip,
            $details->currency,
            $details->issueDate->toString(),
            htmlspecialchars((string) $details->invoiceNumber, \ENT_XML1),
            $rows,
            $amount($details->grossTotal),
            PurchaseInvoiceKind::Correction === $details->kind ? 'KOR' : 'VAT',
        );
    }

    /**
     * Registers and a month of daily reports, as an owner enters them (ADR 0022). The amounts are
     * made up but repeatable; the bar's VAT breakdown leaves out transfers (invoiced events are not
     * on the fiscal report), and a few days have no breakdown yet.
     */
    private function income(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        $registers = [];
        foreach (['Bar', 'Kitchen', 'Terrace'] as $name) {
            $registers[$name] = Register::create(Uuid::v7(), $name, null, $ownerId, MembershipRole::Owner, $now);
            $manager->persist($registers[$name]);
        }
        $registers['Terrace']->deactivate($ownerId, MembershipRole::Owner, $now);
        [$cashMethod, $cardMethod, $transferMethod, $voucherMethod, $blikMethod] = $this->paymentMethods($manager, $organization, $ownerId, $now, ['BLIK']);

        $today = BusinessDate::containing($now, new \DateTimeZone($organization->getTimezone()));
        mt_srand(41);
        $date = $today;
        for ($day = 1; $day <= 31; ++$day) {
            $date = $date->previous();
            $isMonday = 1 === (int) new \DateTimeImmutable($date->toString())->format('N');
            $isWeekend = (int) new \DateTimeImmutable($date->toString())->format('N') >= 5;
            $scale = $isWeekend ? 2 : 1;

            $cash = mt_rand(800, 2500) * 100 * $scale;
            $card = mt_rand(2000, 6000) * 100 * $scale;
            $transfer = 0 === $day % 9 ? mt_rand(5, 20) * 10000 : 0;
            $voucher = 0 === $day % 4 ? mt_rand(1, 3) * 5000 : 0;
            $blik = $day <= 14 ? mt_rand(2, 8) * 1000 * $scale : 0;
            $payments = [new PaymentAmount($cashMethod, $cash), new PaymentAmount($cardMethod, $card)];
            if ($transfer > 0) {
                $payments[] = new PaymentAmount($transferMethod, $transfer);
            }
            if ($voucher > 0) {
                $payments[] = new PaymentAmount($voucherMethod, $voucher);
            }
            if ($blik > 0) {
                $payments[] = new PaymentAmount($blikMethod, $blik);
            }
            $fiscal = $cash + $card + $voucher + $blik;
            $vat = 0 === $day % 10 ? [] : [
                self::vatLine('23', (int) round($fiscal * 0.7), 23),
                self::vatLine('8', (int) round($fiscal * 0.25), 8),
                self::vatLine('5', $fiscal - (int) round($fiscal * 0.7) - (int) round($fiscal * 0.25), 5),
            ];
            $manager->persist($this->entry($organization, $registers['Bar'], $date, $payments, $vat, 0 === $transfer ? null : 'Invoiced event paid by transfer', $ownerId, $today, $now));

            if (!$isMonday) {
                $kitchenCash = mt_rand(300, 1200) * 100 * $scale;
                $kitchenCard = mt_rand(800, 2500) * 100 * $scale;
                $kitchen = [new PaymentAmount($cashMethod, $kitchenCash), new PaymentAmount($cardMethod, $kitchenCard)];
                $manager->persist($this->entry($organization, $registers['Kitchen'], $date, $kitchen, [self::vatLine('8', $kitchenCash + $kitchenCard, 8)], null, $ownerId, $today, $now));
            }
        }
    }

    /**
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     */
    private function entry(Organization $organization, Register $register, BusinessDate $date, array $payments, array $vat, ?string $note, Uuid $ownerId, BusinessDate $today, \DateTimeImmutable $now): RevenueEntry
    {
        return RevenueEntry::record(
            Uuid::v7(),
            $register,
            $date,
            $organization->getCurrency(),
            $organization->getCountryCode(),
            $payments,
            $vat,
            $note,
            $ownerId,
            MembershipRole::Owner,
            $today,
            $now,
        );
    }

    /** VAT in a gross amount at a percentage rate, rounded as a fiscal printer would. */
    private static function vatLine(string $rate, int $gross, int $percent): VatAmount
    {
        return new VatAmount($rate, $gross, (int) round($gross * $percent / (100 + $percent)));
    }

    private function user(ObjectManager $manager, string $email, string $firstName, string $lastName, \DateTimeImmutable $now): User
    {
        $user = new User(Uuid::v7(), $email, $now, $firstName, $lastName);
        $user->changePassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
