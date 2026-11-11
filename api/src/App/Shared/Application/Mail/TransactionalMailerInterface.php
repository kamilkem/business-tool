<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

/**
 * Port for transactional e-mail (adr/0012). Sending is asynchronous: send() queues the e-mail and
 * returns; delivery problems surface in the worker and the `failed` transport, not in the caller.
 * Call it after the write it reports on has succeeded (e.g. from a domain event handler).
 */
interface TransactionalMailerInterface
{
    public function send(TransactionalEmail $email): void;
}
