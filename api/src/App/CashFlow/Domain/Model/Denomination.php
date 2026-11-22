<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Exception\InvalidDenominationValueException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A coin or note the organization counts its cash in (ADR 0033 §2), with its face value in minor
 * units of its currency (ADR 0010).
 *
 * - A value is unique per organization and currency, deactivated denominations included: the model
 *   checks it against the denomination with the same value (DenominationRepositoryInterface::findByValue()),
 *   and the unique constraint below stops two concurrent writes.
 * - Only the denominations of the organization's current currency are offered; the currency is the
 *   organization's when the denomination is added and never changes.
 * - One that no close or movement uses can be deleted. A used one is deactivated instead, and can be
 *   reactivated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_denomination')]
#[ORM\UniqueConstraint(name: self::UNIQUE_VALUE, columns: ['organization_id', 'currency', 'value'])]
class Denomination implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, currency, value); the repository adapter recognizes its violations. */
    public const string UNIQUE_VALUE = 'cash_denomination_value_unique';

    /** The largest face value, in minor units: the largest PostgreSQL INTEGER. */
    public const int MAX_VALUE = 2_147_483_647;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** ISO 4217, the organization's when the denomination was added. */
    #[ORM\Column(length: 3)]
    private string $currency;

    /** Face value in minor units (grosze for PLN). */
    #[ORM\Column]
    private int $value;

    #[ORM\Column(length: 8, enumType: DenominationKind::class)]
    private DenominationKind $kind;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    private function __construct(Uuid $id, string $currency, int $value, DenominationKind $kind, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->currency = $currency;
        $this->value = $value;
        $this->kind = $kind;
        $this->createdAt = $createdAt;
    }

    /**
     * @param string        $currency  the organization's currency (ISO 4217)
     * @param ?Denomination $sameValue the organization's denomination with this currency and value, if any
     *                                 (DenominationRepositoryInterface::findByValue())
     *
     * @throws CashFlowAccessForbiddenException  when the actor is not an owner or manager
     * @throws InvalidDenominationValueException when the value is not positive or too large
     * @throws DenominationValueTakenException   when the organization already has the value
     */
    public static function create(Uuid $id, string $currency, int $value, DenominationKind $kind, ?self $sameValue, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if ($value < 1 || $value > self::MAX_VALUE) {
            throw InvalidDenominationValueException::of($value, self::MAX_VALUE);
        }
        $currency = strtoupper($currency);
        if (null !== $sameValue) {
            throw DenominationValueTakenException::of($currency, $value);
        }

        return new self($id, $currency, $value, $kind, $now);
    }

    /**
     * The denomination is no longer offered from now on. Deactivating it again keeps the first moment.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function deactivate(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt ??= $now;
    }

    /**
     * The denomination is offered again. Reactivating an active one changes nothing.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function reactivate(Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt = null;
    }

    /**
     * Checks that the denomination may be deleted: only one that no close or movement uses.
     *
     * @param bool $used whether a close or movement uses it (DenominationRepositoryInterface::isUsed())
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws DenominationInUseException       when it is used
     */
    public function assertDeletable(bool $used, Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if ($used) {
            throw DenominationInUseException::of($this->id->toRfc4122(), $this->currency, $this->value);
        }
    }

    public function isActive(): bool
    {
        return null === $this->deactivatedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getKind(): DenominationKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    /** The order of lists: active denominations first, then by value, lowest first. */
    public static function compare(self $a, self $b): int
    {
        return [$a->isActive() ? 0 : 1, $a->value, $a->id->toRfc4122()] <=> [$b->isActive() ? 0 : 1, $b->value, $b->id->toRfc4122()];
    }
}
