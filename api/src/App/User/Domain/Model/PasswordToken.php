<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

use App\User\Domain\Exception\InvalidPasswordTokenException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A one-time permission to set a user's password, sent by e-mail as a link (ADR 0013): an
 * invitation for a new member or a password reset. Global, like User: not tenant-aware. Only the
 * hash of the secret is stored.
 *
 * A token works once and until it expires. Issuing a new token invalidates the user's unused tokens
 * of the same purpose, and setting a password invalidates all of them (see the handlers in
 * App\User\Application).
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_token')]
class PasswordToken
{
    /** SHA-256 of the secret (PasswordTokenSecret), hex-encoded. */
    #[ORM\Column(length: 64, unique: true)]
    private string $secretHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** When the password was set with this token. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** When a newer token or a password change made this one useless. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private Uuid $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, enumType: PasswordTokenPurpose::class)]
        private PasswordTokenPurpose $purpose,
        PasswordTokenSecret $secret,
        \DateTimeImmutable $now,
    ) {
        $this->secretHash = $secret->hash();
        $this->createdAt = $now;
        $this->expiresAt = $now->add($purpose->lifetime());
    }

    /** Neither used, invalidated nor expired. */
    public function isValidAt(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && null === $this->invalidatedAt && $now < $this->expiresAt;
    }

    /**
     * Spends the token on setting the user's password.
     *
     * @throws InvalidPasswordTokenException when it was used, invalidated or has expired
     */
    public function use(\DateTimeImmutable $now): void
    {
        if (!$this->isValidAt($now)) {
            throw InvalidPasswordTokenException::create();
        }

        $this->usedAt = $now;
    }

    /** Makes an unused token useless; a used or already invalidated one keeps its history. */
    public function invalidate(\DateTimeImmutable $now): void
    {
        if (null === $this->usedAt && null === $this->invalidatedAt) {
            $this->invalidatedAt = $now;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): PasswordTokenPurpose
    {
        return $this->purpose;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->invalidatedAt;
    }
}
