<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\DecimalString;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What the owner confirmed, remembered (ADR 0042 §2): one key of one kind points to one product, and
 * may carry the package size of the pack it names. The last confirmation of a key wins. Matching
 * finds the product of an item by these before it asks the model, and never writes one: only a
 * person's confirmation does, so a mistake of the matching cannot reinforce itself.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_alias')]
#[ORM\UniqueConstraint(name: self::UNIQUE_KEY, columns: ['organization_id', 'kind', 'alias_key'])]
class ProductAlias implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const string UNIQUE_KEY = 'product_alias_unique_key';

    public const int KEY_MAX_LENGTH = 1024;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: AliasKind::class)]
    private AliasKind $kind;

    #[ORM\Column(name: 'alias_key', length: self::KEY_MAX_LENGTH)]
    private string $key;

    /** The alias goes with its product (a merge moves it first, MergeProductHandler). */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: PurchasedItem::PACKAGE_SIZE_SCALE, nullable: true)]
    private ?string $packageSize;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, AliasKind $kind, string $key, Product $product, ?string $packageSize, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->key = mb_substr($key, 0, self::KEY_MAX_LENGTH);
        $this->product = $product;
        $this->packageSize = $kind->carriesPackageSize() ? $packageSize : null;
        $this->updatedAt = $now;
    }

    /** A later confirmation of the same key: it points to the product it was confirmed to, with the size it was confirmed with. */
    public function repoint(Product $product, ?string $packageSize, \DateTimeImmutable $now): void
    {
        $this->product = $product;
        $this->packageSize = $this->kind->carriesPackageSize() ? $packageSize : null;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): AliasKind
    {
        return $this->kind;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    /** A decimal string with trailing zeros trimmed; null when the alias remembers none. */
    public function getPackageSize(): ?string
    {
        return DecimalString::trimmed($this->packageSize);
    }
}
