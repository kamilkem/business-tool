<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/**
 * Who may do what with the organization's KSeF connection, and for which organizations it exists.
 * Owners and managers see the connection and test it (ADR 0021, ADR 0023 §5); only owners save or
 * remove the token (ADR 0023 §2, ADR 0026 §5). KSeF exists only for organizations in Poland with a
 * valid NIP (ADR 0002 §3, ADR 0023 §3). The caller passes the role of an active member (ADR 0014).
 */
final class KsefAccess
{
    /** @throws KsefConnectionForbiddenException when the role is employee */
    public static function assertCanUse(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw KsefConnectionForbiddenException::toUse($actorId->toRfc4122());
        }
    }

    /** @throws KsefConnectionForbiddenException when the role is not owner */
    public static function assertCanManageToken(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (MembershipRole::Owner !== $actorRole) {
            throw KsefConnectionForbiddenException::toManageToken($actorId->toRfc4122());
        }
    }

    /**
     * The NIP KSeF knows the organization by.
     *
     * @throws KsefNotAvailableException when the country is not PL or the tax ID is not a valid NIP
     */
    public static function nipFor(string $countryCode, ?string $taxId): Nip
    {
        if ('PL' !== strtoupper($countryCode)) {
            throw KsefNotAvailableException::outsidePoland($countryCode);
        }

        return Nip::tryFromTaxId($taxId) ?? throw KsefNotAvailableException::invalidNip();
    }
}
