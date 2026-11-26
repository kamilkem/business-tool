<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * How many pieces a seller's unit holds, as the owner confirmed it (ADR 0043): `CO` of one seller is 6,
 * `kart.` of another 24. A unit of unknown count (a case, a carton, a seller's own word) gives no package
 * size on its own; once a person confirmed an item of that seller in that unit, every item of that
 * seller in that unit is read with the multiplier. The last confirmation wins. Written only by a
 * person's confirmation, never by matching.
 */
#[ORM\Entity]
#[ORM\Table(name: 'seller_unit')]
#[ORM\UniqueConstraint(name: self::UNIQUE_UNIT, columns: ['organization_id', 'seller_key', 'unit_key'])]
class SellerUnit implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const string UNIQUE_UNIT = 'seller_unit_unique_unit';

    public const int SELLER_KEY_MAX_LENGTH = 300;
    public const int UNIT_KEY_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** AliasKeys::sellerOf(): the seller's tax ID, or `name:` and its name. */
    #[ORM\Column(length: self::SELLER_KEY_MAX_LENGTH)]
    private string $sellerKey;

    /** ItemReader::unitKeyOf(): the unit as printed, lower case, no diacritics. */
    #[ORM\Column(length: self::UNIT_KEY_MAX_LENGTH)]
    private string $unitKey;

    /** Pieces in one unit of the quantity, at least 1. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $multiplier;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, string $sellerKey, string $unitKey, int $multiplier, \DateTimeImmutable $now)
    {
        if ($multiplier < 1) {
            throw new \InvalidArgumentException('A unit holds at least one piece.');
        }
        $this->id = $id;
        $this->sellerKey = mb_substr($sellerKey, 0, self::SELLER_KEY_MAX_LENGTH);
        $this->unitKey = mb_substr($unitKey, 0, self::UNIT_KEY_MAX_LENGTH);
        $this->multiplier = $multiplier;
        $this->updatedAt = $now;
    }

    public function change(int $multiplier, \DateTimeImmutable $now): void
    {
        if ($multiplier < 1) {
            throw new \InvalidArgumentException('A unit holds at least one piece.');
        }
        $this->multiplier = $multiplier;
        $this->updatedAt = $now;
    }

    public function getSellerKey(): string
    {
        return $this->sellerKey;
    }

    public function getUnitKey(): string
    {
        return $this->unitKey;
    }

    public function getMultiplier(): int
    {
        return $this->multiplier;
    }
}
