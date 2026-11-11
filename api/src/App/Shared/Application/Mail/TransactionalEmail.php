<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

/**
 * One transactional e-mail to one recipient (adr/0012). The body is a Twig template rendered by the
 * adapter; the plain-text part is derived from the HTML. The context holds primitives only, so the
 * message stays serializable on the queue.
 */
final readonly class TransactionalEmail
{
    /**
     * @param string                     $template path of an HTML Twig template, e.g. "email/invitation.html.twig"
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $template,
        public array $context = [],
    ) {
    }
}
