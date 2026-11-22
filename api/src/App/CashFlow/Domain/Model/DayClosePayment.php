<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The day's amount of one non-cash payment method at a DayClose, created only through it (ADR 0033
 * §6). The method is Income's, referred to by id only (ADR 0004).
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_day_close_payment')]
class DayClosePayment implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'payments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DayClose $dayClose,
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $paymentMethodId,
        /** Minor units, at most RevenueEntry::MAX_AMOUNT. */
        #[ORM\Column]
        private int $amount,
    ) {
    }

    public function changeAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getPaymentMethodId(): Uuid
    {
        return $this->paymentMethodId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }
}
