<?php

declare(strict_types=1);

namespace App\User\Application\Command\SendInvitation;

use App\Shared\Application\Command\CommandInterface;

/**
 * Issues an invitation token for a member who has not set a password yet and e-mails them the link
 * (ADR 0013). Sent when the member is added and again on request; the new token replaces the old.
 */
final readonly class SendInvitationCommand implements CommandInterface
{
    public function __construct(
        public string $tokenId,
        public string $userId,
        /** The organization the person was invited to, named in the e-mail. */
        public string $organizationId,
        /** Their role there: it decides whether the link opens the admin panel or the time clock. */
        public string $role,
        /** The owner or manager who invited them, named in the e-mail. */
        public string $invitedById,
    ) {
    }
}
