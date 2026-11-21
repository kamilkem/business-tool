<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

use App\Shared\Domain\Language\Language;

/**
 * One transactional e-mail to one recipient (adr/0012), in the recipient's language (ADR 0028 §6).
 * The subject is a key of the `email` translation domain, translated with its parameters by the
 * adapter; the body is a Twig template rendered by the worker in the same locale, so its `|trans`
 * use it too. The plain-text part is derived from the HTML. The context holds primitives only, so
 * the message stays serializable on the queue.
 */
final readonly class TransactionalEmail
{
    /**
     * @param string                          $locale            the recipient's language code (a Language value)
     * @param string                          $subject           a key of the `email` domain, e.g. "invitation.subject"
     * @param string                          $template          path of an HTML Twig template, e.g. "email/invitation.html.twig"
     * @param array<string, scalar|null>      $context
     * @param array<string, string|int|float> $subjectParameters ICU parameters of the subject
     */
    public function __construct(
        public string $to,
        public string $locale,
        public string $subject,
        public string $template,
        public array $context = [],
        public array $subjectParameters = [],
    ) {
    }

    /** The locale of a recipient's language setting; English when they never chose one. */
    public static function localeOf(?Language $language): string
    {
        return ($language ?? Language::English)->value;
    }
}
