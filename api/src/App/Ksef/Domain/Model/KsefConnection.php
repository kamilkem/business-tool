<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The organization's link to KSeF (ADR 0023 §3, ADR 0024 §3), at most one per organization. It keeps
 * the id of its `ksef_token` secret in App\Secret (ADR 0026 §1), never the token; whether the token
 * works (the status and the last check); and the state of the import, which the sync fills (#52).
 * It exists only for organizations in Poland with a valid NIP.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ksef_connection')]
#[ORM\UniqueConstraint(name: 'ksef_connection_organization_unique', columns: ['organization_id'])]
class KsefConnection implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int ERROR_MESSAGE_MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The `ksef_token` secret; null after the token was removed. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $secretId;

    #[ORM\Column(length: 32, enumType: KsefConnectionStatus::class)]
    private KsefConnectionStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When KSeF last accepted the current token (a check or a sync); null until then. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** When the current token was last checked ("test connection"). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(length: 32, nullable: true, enumType: KsefErrorCode::class)]
    private ?KsefErrorCode $lastCheckError = null;

    #[ORM\Column(length: self::ERROR_MESSAGE_MAX_LENGTH, nullable: true)]
    private ?string $lastCheckErrorMessage = null;

    /** The sync cursor (ADR 0023 §4): a UTC instant below which every invoice is imported; null until the first run. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncCursor = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncSucceededAt = null;

    #[ORM\Column(length: 32, nullable: true, enumType: KsefErrorCode::class)]
    private ?KsefErrorCode $lastSyncError = null;

    #[ORM\Column(length: self::ERROR_MESSAGE_MAX_LENGTH, nullable: true)]
    private ?string $lastSyncErrorMessage = null;

    #[ORM\Column]
    private int $lastSyncImportedCount = 0;

    #[ORM\Column]
    private int $lastSyncSkippedCount = 0;

    private function __construct(Uuid $id, Uuid $secretId, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->secretId = $secretId;
        $this->status = KsefConnectionStatus::Active;
        $this->createdAt = $createdAt;
    }

    /**
     * The first token of the organization was saved.
     *
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     */
    public static function connect(Uuid $id, Uuid $secretId, string $countryCode, ?string $taxId, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        KsefAccess::assertCanManageToken($actorId, $actorRole);
        KsefAccess::nipFor($countryCode, $taxId);

        return new self($id, $secretId, $now);
    }

    /**
     * An owner saved a new token (in the secret with this id): the connection is active again and
     * the token is not verified yet.
     *
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     */
    public function tokenSaved(Uuid $secretId, string $countryCode, ?string $taxId, Uuid $actorId, MembershipRole $actorRole): void
    {
        KsefAccess::assertCanManageToken($actorId, $actorRole);
        KsefAccess::nipFor($countryCode, $taxId);

        $this->secretId = $secretId;
        $this->status = KsefConnectionStatus::Active;
        $this->forgetChecks();
    }

    /**
     * An owner removed the token: nothing is imported until a new one is saved. The sync state stays,
     * so a new token continues from the cursor.
     *
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     */
    public function tokenRemoved(Uuid $actorId, MembershipRole $actorRole): void
    {
        KsefAccess::assertCanManageToken($actorId, $actorRole);

        $this->secretId = null;
        $this->status = KsefConnectionStatus::Disabled;
        $this->forgetChecks();
    }

    /**
     * The outcome of checking the token against KSeF. Success verifies it and makes the connection
     * active; a rejected or unreadable token makes it `credentials_rejected`; any other error leaves
     * the status as it was.
     */
    public function recordCheck(\DateTimeImmutable $checkedAt, ?KsefErrorCode $error = null, ?string $errorMessage = null): void
    {
        $this->lastCheckedAt = $checkedAt;
        $this->lastCheckError = $error;
        $this->lastCheckErrorMessage = null === $error ? null : self::truncate($errorMessage);

        if (null === $error) {
            $this->verifiedAt = $checkedAt;
            $this->status = KsefConnectionStatus::Active;
        } elseif ($error->rejectsCredentials()) {
            $this->status = KsefConnectionStatus::CredentialsRejected;
        }
    }

    /**
     * The outcome of one sync run (#52): the cursor it reached, what it imported and skipped, and the
     * error that ended it, if any. A rejected or unreadable token stops further runs until the owner
     * saves a new one (ADR 0023 §5).
     */
    public function recordSyncRun(\DateTimeImmutable $ranAt, ?\DateTimeImmutable $cursor, int $imported, int $skipped, ?KsefErrorCode $error = null, ?string $errorMessage = null): void
    {
        $this->lastSyncAt = $ranAt;
        $this->syncCursor = $cursor;
        $this->lastSyncImportedCount = $imported;
        $this->lastSyncSkippedCount = $skipped;
        $this->lastSyncError = $error;
        $this->lastSyncErrorMessage = null === $error ? null : self::truncate($errorMessage);

        if (null === $error) {
            $this->lastSyncSucceededAt = $ranAt;
            $this->verifiedAt = $ranAt;
        } elseif ($error->rejectsCredentials()) {
            $this->status = KsefConnectionStatus::CredentialsRejected;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSecretId(): ?Uuid
    {
        return $this->secretId;
    }

    public function getStatus(): KsefConnectionStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastCheckError(): ?KsefErrorCode
    {
        return $this->lastCheckError;
    }

    public function getLastCheckErrorMessage(): ?string
    {
        return $this->lastCheckErrorMessage;
    }

    public function getSyncCursor(): ?\DateTimeImmutable
    {
        return $this->syncCursor;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function getLastSyncSucceededAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncSucceededAt;
    }

    public function getLastSyncError(): ?KsefErrorCode
    {
        return $this->lastSyncError;
    }

    public function getLastSyncErrorMessage(): ?string
    {
        return $this->lastSyncErrorMessage;
    }

    public function getLastSyncImportedCount(): int
    {
        return $this->lastSyncImportedCount;
    }

    public function getLastSyncSkippedCount(): int
    {
        return $this->lastSyncSkippedCount;
    }

    private function forgetChecks(): void
    {
        $this->verifiedAt = null;
        $this->lastCheckedAt = null;
        $this->lastCheckError = null;
        $this->lastCheckErrorMessage = null;
    }

    private static function truncate(?string $message): ?string
    {
        return null === $message ? null : mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH);
    }
}
