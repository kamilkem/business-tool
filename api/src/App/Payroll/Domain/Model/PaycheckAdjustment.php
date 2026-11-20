<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Payroll\Domain\Exception\InvalidPaycheckAdjustmentException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A fixed amount added to or taken off a paycheck (ADR 0027 §6): a bonus (+) or an advance already
 * paid (−), in the run's currency. Kept when the run is regenerated. Created through the PayrollRun.
 */
#[ORM\Entity]
#[ORM\Table(name: 'paycheck_adjustment')]
class PaycheckAdjustment implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int MAX_LABEL_LENGTH = 100;

    /** The largest amount either way in minor units: the largest PostgreSQL INTEGER. */
    public const int MAX_AMOUNT = 2_147_483_647;

    #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
    private string $label;

    /** Signed minor units. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @throws InvalidPaycheckAdjustmentException */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private Uuid $id,
        #[ORM\ManyToOne(inversedBy: 'adjustments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Paycheck $paycheck,
        string $label,
        int $amount,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->label = self::assertLabel($label);
        $this->amount = self::assertAmount($amount);
    }

    /** @throws InvalidPaycheckAdjustmentException */
    public function change(string $label, int $amount, \DateTimeImmutable $now): void
    {
        $label = self::assertLabel($label);
        self::assertAmount($amount);
        if ($label === $this->label && $amount === $this->amount) {
            return;
        }
        $this->label = $label;
        $this->amount = $amount;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPaycheck(): Paycheck
    {
        return $this->paycheck;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @throws InvalidPaycheckAdjustmentException */
    private static function assertLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw InvalidPaycheckAdjustmentException::label(self::MAX_LABEL_LENGTH);
        }

        return $label;
    }

    /** @throws InvalidPaycheckAdjustmentException */
    private static function assertAmount(int $amount): int
    {
        if (0 === $amount || $amount > self::MAX_AMOUNT || $amount < -self::MAX_AMOUNT) {
            throw InvalidPaycheckAdjustmentException::amount($amount, self::MAX_AMOUNT);
        }

        return $amount;
    }
}
