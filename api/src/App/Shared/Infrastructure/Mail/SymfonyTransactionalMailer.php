<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Symfony Mailer adapter. The provider comes from MAILER_DSN and the sender from MAILER_FROM; the
 * mailer hands the e-mail to the `async` Messenger transport (config/packages/mailer.yaml).
 *
 * The subject is translated here, in the recipient's locale. The body is rendered later by the
 * worker, which has no request locale: TemplatedEmail::locale() makes the body renderer switch the
 * translator to it while rendering (ADR 0028 §6).
 */
final readonly class SymfonyTransactionalMailer implements TransactionalMailerInterface
{
    public const string DOMAIN = 'email';

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function send(TransactionalEmail $email): void
    {
        $this->mailer->send(
            new TemplatedEmail()
                ->from(Address::create($this->from))
                ->to(new Address($email->to))
                ->locale($email->locale)
                ->subject($this->translator->trans($email->subject, $email->subjectParameters, self::DOMAIN, $email->locale))
                ->htmlTemplate($email->template)
                ->context($email->context),
        );
    }
}
