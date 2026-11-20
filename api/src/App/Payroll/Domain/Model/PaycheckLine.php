<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A snapshot of one shift on a paycheck (ADR 0027 §6): an approved run never changes when the shift
 * or the rates change later. Created and replaced through the Paycheck only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'paycheck_line')]
class PaycheckLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Column(type: 'business_date')]
    private BusinessDate $date;

    #[ORM\Column]
    private int $seconds;

    #[ORM\Column]
    private int $paidSeconds;

    #[ORM\Column(length: 8, nullable: true, enumType: ShiftRateKind::class)]
    private ?ShiftRateKind $rateKind;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $rateId;

    /** Gross minor units per hour; null when the shift has no rate. */
    #[ORM\Column(nullable: true)]
    private ?int $rate;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Paycheck $paycheck,
        /** The shift (App\TimeTracking) the line pays. */
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $shiftId,
        ShiftLine $line,
    ) {
        $this->replaceWith($line);
    }

    /** Takes a recomputed line of the same shift. */
    public function replaceWith(ShiftLine $line): void
    {
        if (!$line->shiftId->equals($this->shiftId)) {
            throw new \LogicException(\sprintf('Line of shift "%s" cannot take the values of shift "%s".', $this->shiftId->toRfc4122(), $line->shiftId->toRfc4122()));
        }
        $this->date = $line->date;
        $this->seconds = $line->seconds;
        $this->paidSeconds = $line->paidSeconds;
        $this->rateKind = $line->rateKind;
        $this->rateId = $line->rateId;
        $this->rate = $line->rate;
    }

    public function getShiftId(): Uuid
    {
        return $this->shiftId;
    }

    public function toShiftLine(): ShiftLine
    {
        return new ShiftLine($this->shiftId, $this->date, $this->seconds, $this->paidSeconds, $this->rateKind, $this->rateId, $this->rate);
    }
}
