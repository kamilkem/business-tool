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

    /** Single packages in one unit of the quantity, with the package size of one single package (ADR 0048); null: the alias remembers no bundle. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $bundleCount;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, AliasKind $kind, string $key, Product $product, ?string $packageSize, \DateTimeImmutable $now, ?int $bundleCount = null)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->key = mb_substr($key, 0, self::KEY_MAX_LENGTH);
        $this->product = $product;
        $this->remember($packageSize, $bundleCount);
        $this->updatedAt = $now;
    }

    /** A later confirmation of the same key: it points to the product it was confirmed to, with the size it was confirmed with. */
    public function repoint(Product $product, ?string $packageSize, \DateTimeImmutable $now, ?int $bundleCount = null): void
    {
        $this->product = $product;
        $this->remember($packageSize, $bundleCount);
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

    /** A decimal string with trailing zeros trimmed: the size of one single package when it is a bundle; null when the alias remembers none. */
    public function getPackageSize(): ?string
    {
        return DecimalString::trimmed($this->packageSize);
    }

    public function getBundleCount(): ?int
    {
        return $this->bundleCount;
    }

    /** The remembered package size and bundle count together; null when it remembers no size. */
    public function getPackage(): ?PackageSize
    {
        $size = $this->getPackageSize();

        return null === $size ? null : PackageSize::of($size, $this->bundleCount);
    }

    /** Only the alias kinds that name a pack remember a size, and a count goes with a size. */
    private function remember(?string $packageSize, ?int $bundleCount): void
    {
        $carries = $this->kind->carriesPackageSize() && null !== $packageSize;
        $this->packageSize = $carries ? $packageSize : null;
        $this->bundleCount = $carries ? $bundleCount : null;
    }
}
