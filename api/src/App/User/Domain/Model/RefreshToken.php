<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

use App\User\Domain\Exception\InvalidRefreshTokenException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lets a signed-in client get a new JWT without the password (ADR 0020). Global, like User: not
 * tenant-aware. Only the hash of the secret is stored; the secret itself lives in an HttpOnly cookie.
 *
 * Every token issued from one sign-in shares a session id. A token is rotated on use: it gets a
 * successor in the same session. Presenting a rotated token again within ROTATION_GRACE is a retry
 * and gets another successor; after that it is reuse, and the handler revokes the whole session.
 * Each token lives LIFETIME from its issue, but never past MAX_SESSION_LIFETIME from the sign-in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'refresh_token_session_idx', columns: ['session_id'])]
class RefreshToken
{
    public const string LIFETIME = 'P30D';
    public const string MAX_SESSION_LIFETIME = 'P90D';
    public const string ROTATION_GRACE = 'PT30S';

    /** SHA-256 of the secret (RefreshTokenSecret), hex-encoded. */
    #[ORM\Column(length: 64, unique: true)]
    private string $secretHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** When this token got its (first) successor. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    /** When sign-out, a password change, a lost membership or reuse ended the session. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private Uuid $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $sessionId,
        #[ORM\Column]
        private \DateTimeImmutable $sessionStartedAt,
        RefreshTokenSecret $secret,
        \DateTimeImmutable $now,
    ) {
        $this->secretHash = $secret->hash();
        $this->createdAt = $now;
        $this->expiresAt = min(
            $now->add(new \DateInterval(self::LIFETIME)),
            $sessionStartedAt->add(new \DateInterval(self::MAX_SESSION_LIFETIME)),
        );
    }

    /** The first token of a new session, issued at sign-in. */
    public static function issueAtSignIn(Uuid $id, User $user, Uuid $sessionId, RefreshTokenSecret $secret, \DateTimeImmutable $now): self
    {
        return new self($id, $user, $sessionId, $now, $secret, $now);
    }

    /**
     * Spends the token on a successor in the same session. A token rotated less than ROTATION_GRACE
     * ago may be rotated again (a lost response, two tabs at once).
     *
     * @throws InvalidRefreshTokenException when it is revoked, expired or was rotated longer ago
     */
    public function rotate(Uuid $successorId, RefreshTokenSecret $successorSecret, \DateTimeImmutable $now): self
    {
        if (!$this->isUsableAt($now)) {
            throw InvalidRefreshTokenException::create();
        }

        $this->rotatedAt ??= $now;

        return new self($successorId, $this->user, $this->sessionId, $this->sessionStartedAt, $successorSecret, $now);
    }

    /** Neither revoked nor expired, and not rotated or rotated within the grace period. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt
            && $now < $this->expiresAt
            && (null === $this->rotatedAt || $this->rotatedAt >= self::rotationGraceCutoff($now));
    }

    /**
     * A rotated token presented after the grace period: someone kept an old copy, so the session is
     * treated as stolen. Revoked and expired tokens are not reuse; they are just invalid.
     */
    public function isReusedAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt
            && $now < $this->expiresAt
            && null !== $this->rotatedAt
            && $this->rotatedAt < self::rotationGraceCutoff($now);
    }

    /** Tokens rotated before this moment can no longer be presented. */
    public static function rotationGraceCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(self::ROTATION_GRACE));
    }

    /** Idempotent: a revoked token keeps the moment of its first revocation. */
    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getSessionStartedAt(): \DateTimeImmutable
    {
        return $this->sessionStartedAt;
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

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
