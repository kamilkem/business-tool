<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Symfony Mailer adapter. The provider comes from MAILER_DSN and the sender from MAILER_FROM; the
 * mailer hands the e-mail to the `async` Messenger transport (config/packages/mailer.yaml).
 */
final readonly class SymfonyTransactionalMailer implements TransactionalMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
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
                ->subject($email->subject)
                ->htmlTemplate($email->template)
                ->context($email->context),
        );
    }
}
