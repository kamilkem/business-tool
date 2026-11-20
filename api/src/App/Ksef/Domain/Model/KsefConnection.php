<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefImportTooSoonException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefTokenNotSetException;
use App\Ksef\Domain\Exception\KsefTokenRejectedException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
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

    /** "Import now" is accepted at most once in this many minutes (ADR 0023 §5). */
    public const int IMPORT_REQUEST_INTERVAL_MINUTES = 15;

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

    /** After a 429: KSeF's Retry-After; no run talks to KSeF before it (ADR 0023 §5). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncRetryNotBefore = null;

    /** When an owner or manager last asked for "Import now". */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastImportRequestedAt = null;

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
     * Whether a run may talk to KSeF now: a token is saved and was not rejected, and KSeF's
     * Retry-After after a 429 has passed.
     */
    public function canSync(\DateTimeImmutable $now): bool
    {
        return KsefConnectionStatus::Active === $this->status
            && null !== $this->secretId
            && (null === $this->syncRetryNotBefore || $this->syncRetryNotBefore <= $now);
    }

    /**
     * Where the next run starts (ADR 0023 §4): the cursor, or before the first run the first day of
     * the month before the one the connection was created in, in the organization's time zone.
     */
    public function syncFrom(\DateTimeZone $timeZone): \DateTimeImmutable
    {
        if (null !== $this->syncCursor) {
            return $this->syncCursor;
        }

        $created = BusinessDate::containing($this->createdAt, $timeZone);
        $previousMonth = BusinessDate::of($created->year, $created->month, 1)->previous();

        return BusinessDate::of($previousMonth->year, $previousMonth->month, 1)->startsAt($timeZone)->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Every invoice stored in KSeF before this instant is imported (or skipped): after each invoice
     * and after each complete window (ADR 0023 §4, ADR 0025 §3). The cursor never moves back. It is
     * kept to the whole second, rounded down: the column has no fractions and the database would
     * round up, past invoices not imported yet.
     */
    public function advanceSyncCursor(\DateTimeImmutable $cursor): void
    {
        $cursor = $cursor->setTimezone(new \DateTimeZone('UTC'));
        $cursor = $cursor->setTime((int) $cursor->format('H'), (int) $cursor->format('i'), (int) $cursor->format('s'));
        if (null === $this->syncCursor || $cursor > $this->syncCursor) {
            $this->syncCursor = $cursor;
        }
    }

    /**
     * The outcome of one sync run: what it imported and skipped, and the error that ended it, if any
     * (the cursor moved during the run, advanceSyncCursor()). A rejected or unreadable token stops
     * further runs until the owner saves a new one; after a 429 no run starts before
     * `$retryNotBefore` (ADR 0023 §5). A run that only skipped unreadable invoices completed.
     */
    public function recordSyncRun(
        \DateTimeImmutable $ranAt,
        int $imported,
        int $skipped,
        ?KsefErrorCode $error = null,
        ?string $errorMessage = null,
        ?\DateTimeImmutable $retryNotBefore = null,
    ): void {
        $this->lastSyncAt = $ranAt;
        $this->lastSyncImportedCount = $imported;
        $this->lastSyncSkippedCount = $skipped;
        $this->lastSyncError = $error;
        $this->lastSyncErrorMessage = null === $error ? null : self::truncate($errorMessage);
        $this->syncRetryNotBefore = KsefErrorCode::RateLimited === $error ? $retryNotBefore : null;

        if (null === $error || !$error->endsRun()) {
            $this->lastSyncSucceededAt = $ranAt;
            $this->verifiedAt = $ranAt;
        } elseif ($error->rejectsCredentials()) {
            $this->status = KsefConnectionStatus::CredentialsRejected;
        }
    }

    /**
     * An owner or manager asked for "Import now" (ADR 0023 §5): at most once every 15 minutes, and
     * not while KSeF's Retry-After is running.
     *
     * @throws KsefConnectionForbiddenException when the actor is an employee
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     * @throws KsefTokenNotSetException         when the token was removed
     * @throws KsefTokenRejectedException       when KSeF rejected the token: the owner saves a new one
     * @throws KsefImportTooSoonException       within 15 minutes of the last request, or before Retry-After
     */
    public function requestImport(string $countryCode, ?string $taxId, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        KsefAccess::assertCanUse($actorId, $actorRole);
        KsefAccess::nipFor($countryCode, $taxId);
        if (null === $this->secretId || KsefConnectionStatus::Disabled === $this->status) {
            throw KsefTokenNotSetException::create();
        }
        if (KsefConnectionStatus::CredentialsRejected === $this->status) {
            throw KsefTokenRejectedException::create();
        }
        if (null !== $this->syncRetryNotBefore && $this->syncRetryNotBefore > $now) {
            throw KsefImportTooSoonException::until($this->syncRetryNotBefore, $now);
        }
        if (null !== $this->lastImportRequestedAt) {
            $next = $this->lastImportRequestedAt->modify(\sprintf('+%d minutes', self::IMPORT_REQUEST_INTERVAL_MINUTES));
            if ($next > $now) {
                throw KsefImportTooSoonException::until($next, $now);
            }
        }

        $this->lastImportRequestedAt = $now;
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

    public function getSyncRetryNotBefore(): ?\DateTimeImmutable
    {
        return $this->syncRetryNotBefore;
    }

    public function getLastImportRequestedAt(): ?\DateTimeImmutable
    {
        return $this->lastImportRequestedAt;
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
