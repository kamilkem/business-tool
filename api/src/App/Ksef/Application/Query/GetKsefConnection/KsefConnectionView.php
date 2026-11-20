<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefConnection;

use App\Ksef\Domain\Model\KsefConnection;

/** The KSeF connection without its token (ADR 0026 §5). */
final readonly class KsefConnectionView
{
    public function __construct(
        public string $id,
        public string $status,
        public bool $tokenSet,
        public ?\DateTimeImmutable $tokenSetAt,
        public ?string $nip,
        public ?\DateTimeImmutable $verifiedAt,
        public ?\DateTimeImmutable $lastCheckedAt,
        public ?string $lastCheckError,
        public ?string $lastCheckErrorMessage,
        public ?\DateTimeImmutable $syncedUntil,
        public ?\DateTimeImmutable $lastSyncAt,
        public ?\DateTimeImmutable $lastSyncSucceededAt,
        public ?string $lastSyncError,
        public ?string $lastSyncErrorMessage,
        public int $lastSyncImportedCount,
        public int $lastSyncSkippedCount,
        public ?\DateTimeImmutable $syncRetryNotBefore,
        public ?\DateTimeImmutable $lastImportRequestedAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromConnection(KsefConnection $connection, ?\DateTimeImmutable $tokenSetAt, ?string $nip): self
    {
        return new self(
            $connection->getId()->toRfc4122(),
            $connection->getStatus()->value,
            null !== $tokenSetAt,
            $tokenSetAt,
            $nip,
            $connection->getVerifiedAt(),
            $connection->getLastCheckedAt(),
            $connection->getLastCheckError()?->value,
            $connection->getLastCheckErrorMessage(),
            $connection->getSyncCursor(),
            $connection->getLastSyncAt(),
            $connection->getLastSyncSucceededAt(),
            $connection->getLastSyncError()?->value,
            $connection->getLastSyncErrorMessage(),
            $connection->getLastSyncImportedCount(),
            $connection->getLastSyncSkippedCount(),
            $connection->getSyncRetryNotBefore(),
            $connection->getLastImportRequestedAt(),
            $connection->getCreatedAt(),
        );
    }
}
