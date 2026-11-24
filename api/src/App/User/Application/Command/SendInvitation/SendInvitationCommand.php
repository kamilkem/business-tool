<?php

declare(strict_types=1);

namespace App\User\Application\Command\SendInvitation;

use App\Shared\Application\Command\CommandInterface;

/**
 * Issues an invitation token for a member who has not set a password yet and e-mails them the link
 * (ADR 0013), or only issues it when the inviter passes the link on themselves (ADR 0037). Sent when
 * the member is added and again on request; the new token replaces the old.
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
        /**
         * The owner or manager who invited them, named in the e-mail; null when the account was set up
         * from the console with its organization (app:organization:create, #219).
         */
        public ?string $invitedById,
        /**
         * The secret of the link, when the caller shows the link itself (ADR 0037 §4); null lets the
         * handler generate one. Never logged or put in a message.
         */
        #[\SensitiveParameter]
        public ?string $secret = null,
        /** False when the inviter passes the link on themselves (ADR 0037 §1): no e-mail is queued. */
        public bool $sendEmail = true,
    ) {
    }
}
