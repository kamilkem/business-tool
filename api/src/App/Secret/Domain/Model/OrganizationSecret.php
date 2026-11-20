<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Secret\Domain\Exception\SecretManagementForbiddenException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A per-organization secret, stored encrypted (ADR 0026). The aggregate never sees the key or the
 * plaintext: command handlers encrypt with SecretCipherInterface and pass the EncryptedValue in.
 * Setting it again replaces the value in place, so the id its consumer keeps stays valid. The
 * associated data binds the ciphertext to this row: a value copied into another row or organization
 * does not decrypt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_secret')]
class OrganizationSecret implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Version of the associated data's layout; a new layout needs a new prefix and a re-encryption. */
    private const string ASSOCIATED_DATA_VERSION = 'v1';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: SecretKind::class)]
    private SecretKind $kind;

    #[ORM\Column(length: 64)]
    private string $keyId;

    /** Base64. */
    #[ORM\Column(type: 'text')]
    private string $nonce;

    /** Base64, with the authentication tag. */
    #[ORM\Column(type: 'text')]
    private string $ciphertext;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $setBy;

    #[ORM\Column]
    private \DateTimeImmutable $setAt;

    private function __construct(Uuid $id, SecretKind $kind, EncryptedValue $value, Uuid $setBy, \DateTimeImmutable $setAt)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->keyId = $value->keyId;
        $this->nonce = $value->nonce;
        $this->ciphertext = $value->ciphertext;
        $this->setBy = $setBy;
        $this->setAt = $setAt;
    }

    /**
     * @param EncryptedValue $value encrypted with associatedData() of this organization, id and kind
     *
     * @throws SecretManagementForbiddenException when the actor is not an owner
     */
    public static function create(Uuid $id, SecretKind $kind, EncryptedValue $value, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        SecretAccess::assertCanManage($actorId, $actorRole);

        return new self($id, $kind, $value, $actorId, $now);
    }

    /**
     * The associated data a value of a secret is encrypted with: `v1|<organizationId>|<secretId>|<kind>`.
     * Static, because the first value is encrypted before the aggregate exists.
     */
    public static function associatedData(Uuid $organizationId, Uuid $secretId, SecretKind $kind): string
    {
        return implode('|', [self::ASSOCIATED_DATA_VERSION, $organizationId->toRfc4122(), $secretId->toRfc4122(), $kind->value]);
    }

    /**
     * A new value from an owner, encrypted with associatedData() of this secret's own kind; the id
     * and kind stay. (With a second SecretKind, the handler must also refuse a value of another kind.)
     *
     * @throws SecretManagementForbiddenException when the actor is not an owner
     */
    public function replace(EncryptedValue $value, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        SecretAccess::assertCanManage($actorId, $actorRole);

        $this->keyId = $value->keyId;
        $this->nonce = $value->nonce;
        $this->ciphertext = $value->ciphertext;
        $this->setBy = $actorId;
        $this->setAt = $now;
    }

    /** The same plaintext under another key, during a rotation (ADR 0026 §4). Who set it and when stay. */
    public function reEncrypt(EncryptedValue $value): void
    {
        $this->keyId = $value->keyId;
        $this->nonce = $value->nonce;
        $this->ciphertext = $value->ciphertext;
    }

    /** @throws SecretManagementForbiddenException when the actor is not an owner */
    public function assertCanBeRemovedBy(Uuid $actorId, MembershipRole $actorRole): void
    {
        SecretAccess::assertCanManage($actorId, $actorRole);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): SecretKind
    {
        return $this->kind;
    }

    public function getEncryptedValue(): EncryptedValue
    {
        return new EncryptedValue($this->keyId, $this->nonce, $this->ciphertext);
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function getSetBy(): Uuid
    {
        return $this->setBy;
    }

    public function getSetAt(): \DateTimeImmutable
    {
        return $this->setAt;
    }
}
