<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RegisterDeactivatedException;
use App\Income\Domain\Exception\RevenueEntryInTheFutureException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What one register took on one business date, from its daily report (ADR 0022).
 *
 * - One entry per register and business date: the unique constraint below makes a second one a
 *   conflict (RevenueEntryRepositoryInterface::add()). The register and the date never change; a
 *   wrong one is deleted and entered again.
 * - Gross amounts per payment method, as lines (§4); the total is their sum, computed.
 * - An optional VAT breakdown as printed, with the rates of the organization's country (§6). It does
 *   not have to match the payments.
 * - The currency is the organization's when the entry is recorded, and stays (§5).
 * - Owners and managers only (§7); the entry keeps who recorded it and who changed it last (§8).
 *
 * Amounts are integer minor units (ADR 0010), at most MAX_AMOUNT each (an INTEGER column).
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_entry')]
#[ORM\UniqueConstraint(name: self::ONE_PER_REGISTER_AND_DATE, columns: ['organization_id', 'register_id', 'business_date'])]
// Lists and monthly summaries by business date (#43) within one organization.
#[ORM\Index(name: 'revenue_entry_organization_business_date', columns: ['organization_id', 'business_date'])]
class RevenueEntry implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, register, business date); the repository adapter recognizes its violations. */
    public const string ONE_PER_REGISTER_AND_DATE = 'revenue_entry_one_per_register_and_date';

    /** The largest amount a line takes, in minor units: the largest PostgreSQL INTEGER. */
    public const int MAX_AMOUNT = 2_147_483_647;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Register $register;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $businessDate;

    /** ISO 4217, copied from the organization when the entry is recorded. */
    #[ORM\Column(length: 3)]
    private string $currency;

    /** @var Collection<int, RevenuePaymentLine> */
    #[ORM\OneToMany(targetEntity: RevenuePaymentLine::class, mappedBy: 'revenueEntry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $paymentLines;

    /** @var Collection<int, RevenueVatLine> */
    #[ORM\OneToMany(targetEntity: RevenueVatLine::class, mappedBy: 'revenueEntry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $vatLines;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Who changed the entry last; null until the first edit. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $updatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    private function __construct(Uuid $id, Register $register, BusinessDate $businessDate, string $currency, Uuid $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->register = $register;
        $this->businessDate = $businessDate;
        $this->currency = $currency;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->paymentLines = new ArrayCollection();
        $this->vatLines = new ArrayCollection();
    }

    /**
     * Records a register's daily report.
     *
     * @param string              $currency    the organization's currency (ISO 4217)
     * @param string              $countryCode the organization's country, which gives the VAT rates
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat         may be empty: the breakdown is optional
     * @param BusinessDate        $today       today in the organization's time zone:
     *                                         `BusinessDate::containing($clock->now(), $timeZone)`
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterDeactivatedException       when the register is deactivated
     * @throws RevenueEntryInTheFutureException   when the business date is after today
     * @throws InvalidPaymentLinesException       when the payment lines break a rule
     * @throws InvalidVatLinesException           when the VAT lines break a rule
     */
    public static function record(
        Uuid $id,
        Register $register,
        BusinessDate $businessDate,
        string $currency,
        string $countryCode,
        array $payments,
        array $vat,
        ?string $note,
        Uuid $actorId,
        MembershipRole $actorRole,
        BusinessDate $today,
        \DateTimeImmutable $now,
    ): self {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if (!$register->isActive()) {
            throw RegisterDeactivatedException::of($register->getId()->toRfc4122());
        }
        if ($businessDate->isAfter($today)) {
            throw RevenueEntryInTheFutureException::of($businessDate->toString(), $today->toString());
        }
        self::assertValidPayments($payments);
        self::assertValidVat($vat, $countryCode);

        $entry = new self($id, $register, $businessDate, $currency, $actorId, $now);
        $entry->applyPayments($payments);
        $entry->applyVat($vat);
        $entry->note = self::normalizeNote($note);

        return $entry;
    }

    /**
     * Replaces the payment lines, the VAT lines and the note. The entry may belong to a deactivated
     * register (ADR 0022 §2). A change records who made it and when; setting what the entry already
     * has changes nothing.
     *
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     * @param string              $countryCode the organization's country, which gives the VAT rates
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPaymentLinesException       when the payment lines break a rule
     * @throws InvalidVatLinesException           when the VAT lines break a rule
     */
    public function change(array $payments, array $vat, ?string $note, string $countryCode, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        self::assertValidPayments($payments);
        self::assertValidVat($vat, $countryCode);
        $note = self::normalizeNote($note);

        if (self::samePayments($payments, $this->getPaymentLines()) && self::sameVat($vat, $this->getVatLines()) && $note === $this->note) {
            return;
        }

        $this->applyPayments($payments);
        $this->applyVat($vat);
        $this->note = $note;
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRegister(): Register
    {
        return $this->register;
    }

    public function getBusinessDate(): BusinessDate
    {
        return $this->businessDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** @return list<PaymentAmount> in the order of PaymentMethod::cases() */
    public function getPaymentLines(): array
    {
        $lines = array_map(static fn (RevenuePaymentLine $line): PaymentAmount => $line->toPaymentAmount(), $this->paymentLines->getValues());
        $order = array_flip(array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()));
        usort($lines, static fn (PaymentAmount $a, PaymentAmount $b): int => $order[$a->method->value] <=> $order[$b->method->value]);

        return $lines;
    }

    /** @return list<VatAmount> highest rate first, exempt and other non-numeric rates last */
    public function getVatLines(): array
    {
        $lines = array_map(static fn (RevenueVatLine $line): VatAmount => $line->toVatAmount(), $this->vatLines->getValues());
        usort($lines, static fn (VatAmount $a, VatAmount $b): int => VatRates::compare($a->rate, $b->rate));

        return $lines;
    }

    /** The sum of the payment lines, in minor units. */
    public function getTotal(): int
    {
        return array_sum(array_map(static fn (PaymentAmount $line): int => $line->amount, $this->getPaymentLines()));
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedBy(): ?Uuid
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Changes lines in place, adds new ones and removes the rest, so a method kept across an edit
     * keeps its row (a remove and an insert of the same key in one flush would collide).
     *
     * @param list<PaymentAmount> $payments
     */
    private function applyPayments(array $payments): void
    {
        $wanted = [];
        foreach ($payments as $payment) {
            $wanted[$payment->method->value] = $payment->amount;
        }

        foreach ($this->paymentLines as $line) {
            $method = $line->getMethod()->value;
            if (\array_key_exists($method, $wanted)) {
                $line->changeAmount($wanted[$method]);
                unset($wanted[$method]);
            } else {
                $this->paymentLines->removeElement($line);
            }
        }

        foreach ($wanted as $method => $amount) {
            $this->paymentLines->add(new RevenuePaymentLine($this, PaymentMethod::from($method), $amount));
        }
    }

    /**
     * As applyPayments(), by rate.
     *
     * @param list<VatAmount> $vat
     */
    private function applyVat(array $vat): void
    {
        $wanted = [];
        foreach ($vat as $line) {
            $wanted[$line->rate] = $line;
        }

        foreach ($this->vatLines as $line) {
            $rate = $line->getRate();
            if (\array_key_exists($rate, $wanted)) {
                $line->change($wanted[$rate]->gross, $wanted[$rate]->vat);
                unset($wanted[$rate]);
            } else {
                $this->vatLines->removeElement($line);
            }
        }

        foreach ($wanted as $line) {
            $this->vatLines->add(new RevenueVatLine($this, $line->rate, $line->gross, $line->vat));
        }
    }

    /**
     * @param list<PaymentAmount> $payments
     *
     * @throws InvalidPaymentLinesException
     */
    private static function assertValidPayments(array $payments): void
    {
        if ([] === $payments) {
            throw InvalidPaymentLinesException::empty();
        }

        $seen = [];
        foreach ($payments as $payment) {
            $method = $payment->method->value;
            if (isset($seen[$method])) {
                throw InvalidPaymentLinesException::duplicateMethod($method);
            }
            if ($payment->amount < 0 || $payment->amount > self::MAX_AMOUNT) {
                throw InvalidPaymentLinesException::amountOutOfRange($method, $payment->amount, self::MAX_AMOUNT);
            }
            $seen[$method] = true;
        }
    }

    /**
     * @param list<VatAmount> $vat
     *
     * @throws InvalidVatLinesException
     */
    private static function assertValidVat(array $vat, string $countryCode): void
    {
        if ([] === $vat) {
            return;
        }
        if ([] === VatRates::forCountry($countryCode)) {
            throw InvalidVatLinesException::noRatesForCountry($countryCode);
        }

        $seen = [];
        foreach ($vat as $line) {
            $rate = VatRates::find($countryCode, $line->rate) ?? throw InvalidVatLinesException::unknownRate($line->rate, $countryCode);
            if (isset($seen[$rate->code])) {
                throw InvalidVatLinesException::duplicateRate($rate->code);
            }
            if ($line->gross < 0 || $line->gross > self::MAX_AMOUNT) {
                throw InvalidVatLinesException::grossOutOfRange($rate->code, $line->gross, self::MAX_AMOUNT);
            }
            if ($line->vat < 0 || $line->vat > $line->gross) {
                throw InvalidVatLinesException::vatOutOfRange($rate->code, $line->vat, $line->gross);
            }
            if ($rate->zeroVat && 0 !== $line->vat) {
                throw InvalidVatLinesException::vatOnZeroRate($rate->code, $line->vat);
            }
            $seen[$rate->code] = true;
        }
    }

    /**
     * @param list<PaymentAmount> $new
     * @param list<PaymentAmount> $current
     */
    private static function samePayments(array $new, array $current): bool
    {
        $key = static function (array $lines): array {
            $byMethod = [];
            foreach ($lines as $line) {
                $byMethod[$line->method->value] = $line->amount;
            }
            ksort($byMethod);

            return $byMethod;
        };

        return $key($new) === $key($current);
    }

    /**
     * @param list<VatAmount> $new
     * @param list<VatAmount> $current
     */
    private static function sameVat(array $new, array $current): bool
    {
        $key = static function (array $lines): array {
            $byRate = [];
            foreach ($lines as $line) {
                $byRate[$line->rate] = [$line->gross, $line->vat];
            }
            ksort($byRate);

            return $byRate;
        };

        return $key($new) === $key($current);
    }

    private static function normalizeNote(?string $note): ?string
    {
        $note = null === $note ? null : trim($note);

        return '' === $note ? null : $note;
    }
}
