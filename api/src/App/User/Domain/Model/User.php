<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

use App\Shared\Domain\Language\Language;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A person who can sign in. Users are global; access to an organization is granted by a
 * Membership (App\Organization).
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private Uuid $id,
        string $email,
        \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 100)]
        private string $firstName = '',
        #[ORM\Column(length: 100)]
        private string $lastName = '',
        /** The UI language they chose (ADR 0028); null until they choose, then the apps follow the browser. */
        #[ORM\Column(length: 8, nullable: true, enumType: Language::class)]
        private ?Language $language = null,
    ) {
        $this->email = self::normalizeEmail($email);
        $this->createdAt = $createdAt;
    }

    /** E-mails are stored trimmed and lower-cased; look them up the same way. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            // Only reachable through a bug: every user is created with an e-mail.
            throw new \LogicException('User has no email.');
        }

        return $this->email;
    }

    /** Organization-level permissions come from Membership, not from global roles. */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** False for an invited user who has not set a password yet (ADR 0013); they cannot sign in. */
    public function hasPassword(): bool
    {
        return '' !== $this->password;
    }

    /** Takes an already hashed password. */
    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    /** Null goes back to "never chosen": the apps then use the browser's language (ADR 0028 §2). */
    public function changeLanguage(?Language $language): void
    {
        $this->language = $language;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
