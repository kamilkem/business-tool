<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The Symfony Mailer adapter end to end in the test environment (adr/0012): the e-mail is queued on
 * the `async` transport (in-memory here), and the worker renders it and delivers it to MAILER_DSN
 * (null://). The queued mailer event carries a rendered preview; the queue itself holds the template
 * and its context.
 */
final class TransactionalMailerTest extends KernelTestCase
{
    public function test_it_queues_the_rendered_email_on_the_async_transport(): void
    {
        $this->mailer()->send($this->welcomeEmail());

        self::assertQueuedEmailCount(1);
        self::assertEmailCount(0);

        $queued = $this->asyncTransport()->getSent();
        self::assertCount(1, $queued);
        self::assertInstanceOf(SendEmailMessage::class, $queued[0]->getMessage());

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'From', 'no-reply@business-tool.localhost');
        self::assertEmailAddressContains($email, 'To', 'anna@example.com');
        self::assertEmailSubjectContains($email, 'Welcome to Demo Bar');
        self::assertEmailHtmlBodyContains($email, 'Hello Anna');
    }

    public function test_it_delivers_the_queued_email_when_the_worker_consumes_it(): void
    {
        $this->mailer()->send($this->welcomeEmail());

        // Same kernel as the send above: the in-memory queue lives in its container.
        self::assertNotNull(self::$kernel);
        $worker = new CommandTester(new Application(self::$kernel)->find('messenger:consume'));
        // --no-reset keeps the mailer's message logger (read by the assertions) after the message.
        $worker->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10, '--no-reset' => null]);

        $worker->assertCommandIsSuccessful();
        self::assertEmailCount(1);
        self::assertCount(0, $this->asyncTransport()->get());
        self::assertCount(1, $this->asyncTransport()->getAcknowledged());

        $sent = array_values(array_filter(self::getMailerEvents(), static fn ($event) => !$event->isQueued()));
        self::assertCount(1, $sent);
        self::assertEmailHtmlBodyContains($sent[0]->getMessage(), 'https://example.com/accept/abc');
        self::assertEmailTextBodyContains($sent[0]->getMessage(), 'Hello Anna');
    }

    private function welcomeEmail(): TransactionalEmail
    {
        return new TransactionalEmail(
            to: 'anna@example.com',
            subject: 'Welcome to Demo Bar',
            template: 'email/test.html.twig',
            context: ['name' => 'Anna', 'link' => 'https://example.com/accept/abc'],
        );
    }

    private function mailer(): TransactionalMailerInterface
    {
        return self::getContainer()->get(TransactionalMailerInterface::class);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
