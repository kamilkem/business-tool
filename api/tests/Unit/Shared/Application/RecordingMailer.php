<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;

/** Fake implementing the port: keeps sent e-mails for assertions. */
final class RecordingMailer implements TransactionalMailerInterface
{
    /** @var list<TransactionalEmail> */
    public array $emails = [];

    public function send(TransactionalEmail $email): void
    {
        $this->emails[] = $email;
    }
}
