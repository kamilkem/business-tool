# 0012. Transactional e-mail: Symfony Mailer over SMTP, queued in PostgreSQL and sent by a worker

Status: Accepted (the worker also consumes the schedule, and a second worker the `jobs` queue: [0025](0025-background-jobs-scheduler-and-job-bus.md))

Date: 2026-11-11

## Context

Inviting a member (#9) and resetting a password (#13) need e-mail; nothing sends mail today. Two
decisions it depends on are still open: production hosting (#35: vendor, region, secrets) and the
runtime for background jobs (#22). Volume is low (a few organizations, so invitations and resets come
to tens or hundreds a month), but the messages carry personal data (employees' addresses, one-time
links), so a provider that processes data in the EU is preferred.

ADR 0004 kept the command, query and event buses in process and rejected an async transport because
"nothing needs it yet". E-mail is the first thing that does: sending over SMTP inside the request
adds the provider's latency to the API call and fails the request when the provider is down, even
though the write it reports on succeeded.

## Decision

1. **Symfony Mailer, provider chosen by `MAILER_DSN` only, over SMTP.** No provider bridge package:
   any relay works with an `smtp://` DSN, and `symfony/http-client` stays a dev dependency. In
   development the DSN points at Mailpit (`mailer` service in `compose.yaml`, set in `api/.env.dev`,
   inbox at http://localhost:8025, `make mail`); tests use `null://null` (`api/.env.test`).
2. **The provider is chosen with hosting (#35)**, against these criteria: processing in the EU with a
   DPA; an SMTP relay with SPF and DKIM for our own domain; free or a few euros a month at hundreds of
   e-mails; bounce and suppression handling. The shortlist, with approximate list prices taken from
   third-party comparisons in November 2026 (check them when signing up):
   - **Brevo** (France): free plan of 300 e-mails a day; paid plans share the quota with marketing.
   - **Mailgun** (EU region available): 100 a day free, about $15 a month for 10,000.
   - **Amazon SES** (e.g. `eu-central-1`): about $0.10–0.16 per 1,000; sending only, so bounces and
     suppression are ours to handle; the natural pick if hosting ends up on AWS.
   - **Postmark** (US): no permanent free tier, about $15 a month for 10,000; known for
     deliverability.
3. **One port for transactional e-mail.** `App\Shared\Application\Mail\TransactionalMailerInterface`
   takes a `TransactionalEmail` (recipient, subject, an HTML Twig template, a context of primitives).
   The adapter `App\Shared\Infrastructure\Mail\SymfonyTransactionalMailer` builds a `TemplatedEmail`;
   the plain-text part is derived from the HTML. Templates go in `api/templates/email/`.
4. **Sent asynchronously.** The mailer dispatches `SendEmailMessage` on its own bus,
   `messenger.bus.mailer` (with Messenger's default middleware), which routes it to the `async`
   transport: a PostgreSQL queue through `symfony/doctrine-messenger` (table `messenger_messages`,
   created by a migration, `auto_setup=0`). A message is retried 3 times (after 1, 5 and 25 minutes)
   and then moved to the `failed` queue in the same table. The command, query and event buses from
   ADR 0004 are unchanged: synchronous, without default middleware.
5. **A worker consumes the queue.** `bin/console messenger:consume async` runs as the `worker`
   service in `compose.yaml`. How it runs in production is part of the hosting decision (#35).
6. **One platform sender.** Every e-mail is sent from `MAILER_FROM`, an address on our own domain
   (`Business Tool <no-reply@…>`); the organization's name appears in the body only.

## Alternatives considered

- **A provider HTTP API bridge** (e.g. `symfony/brevo-mailer`). Better error reporting and webhooks,
  but it ties the dependencies to one provider before hosting is decided and moves
  `symfony/http-client` into production. SMTP is enough at this volume; a bridge can be added later by
  installing the package and changing the DSN scheme, without code changes.
- **Choosing the provider now.** The hosting vendor and region (#35) change the answer: SES, for
  example, only makes sense on AWS. The code depends only on the DSN, so nothing waits for the choice
  except going live.
- **Sending synchronously in the request**, as the buses work today. No worker to run, but a slow or
  unavailable provider slows down or fails the invitation and reset requests.
- **Redis as the queue.** A new service in compose and in production hosting, plus the PHP extension
  in the image and CI, only for e-mail. PostgreSQL is already there and handles this volume.
- **The organization's name as the From display name** ("Bar X via Business Tool"). Not needed for
  invitations and resets; it would add a per-message sender to the port now.
- **Running the worker by hand** (`make worker`). Until someone starts it, e-mails stay queued
  without any visible error.

## Consequences

- **Before going live:** a domain of our own; SPF (include the provider), DKIM (the provider's keys)
  and DMARC (start with `p=none`, tighten later) DNS records; the domain verified at the provider;
  `MAILER_DSN` with the credentials as a secret and `MAILER_FROM` on that domain; a long-running
  worker with a restart policy, and `messenger:stop-workers` on deploy. `bin/console mailer:test`
  checks the DSN; it bypasses the queue.
- **Sending is fire and forget.** `send()` returns once the e-mail is queued; delivery failures
  show up in the worker's log and in `messenger:failed:show`, not in the caller. The buses have no
  transaction middleware (ADR 0004), so the queue insert is not in the same transaction as the write
  it reports on: queue the e-mail after the write succeeded (after `save()` or from a domain event
  handler).
- **Templates are rendered in the worker, not in the request.** The context must stay primitive
  (it is serialized into the queue). The worker must be restarted after a code or template change
  (`make worker-restart`). Once e-mails are translated, the locale has to be set on the e-mail itself:
  the worker has no request locale.
- **Queued e-mails contain personal data** (addresses, one-time links) in `messenger_messages` until
  they are delivered; delivered rows are deleted, and failed ones stay until retried or removed.
- **The first async transport in the system.** The background-jobs spike (#22) can reuse the `async`
  transport and the worker, or supersede points 4 and 5 of this ADR.
- **Tests do not send anything:** the queue is in memory and delivery goes to `null://`. Until #9
  injects the port, the test environment exposes it as a public alias.
