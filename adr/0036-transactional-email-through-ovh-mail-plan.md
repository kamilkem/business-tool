# 0036. Transactional e-mail is sent through the OVHcloud mail plan of tooltipp.com, from one no-reply mailbox

Status: Accepted

Date: 2026-11-23

## Context

ADR [0012](0012-transactional-email-smtp-async-queue.md) sends e-mail with Symfony Mailer over SMTP,
chosen by `MAILER_DSN` alone, and left the provider to hosting. Its criteria: processing in the EU with
a DPA; an SMTP relay with SPF and DKIM on our own domain; free or a few euros a month at hundreds of
e-mails; bounce and suppression handling. ADR [0035](0035-staging-and-production-on-one-ovh-instance.md)
point 12 picked the provider "from ADR 0012's shortlist when the domain is set up", with Brevo as the
default.

The domain is now `tooltipp.com` (#213). It is registered at OVHcloud, its DNS zone is there, and it
comes with an OVHcloud e-mail plan that already receives mail for it (MX `mx1–3.mail.ovh.net`, SPF
`v=spf1 include:mx.ovh.com ~all`). The host is at OVHcloud too (ADR 0035). The owner prefers not to
add another provider and account. Volume is small: invitations, sign-in links and password resets, so
tens to hundreds of e-mails a month.

## Decision

1. **E-mail is sent through the OVHcloud mail plan's SMTP server**, authenticated as one mailbox,
   `no-reply@tooltipp.com`:

   `MAILER_DSN=smtp://no-reply%40tooltipp.com:<password>@smtp.mail.ovh.net:587` (STARTTLS).

   The password goes into each stage's secrets (ADR 0035 point 9), URL-encoded. Both stages use the
   same mailbox for now.
2. **The sender is `Tooltipp <no-reply@tooltipp.com>`** (`MAILER_FROM`), the authenticated mailbox
   itself, so OVHcloud does not reject or rewrite the sender. The organization's name stays in the body
   only (ADR 0012 point 6).
3. **The domain is authenticated with OVHcloud's own records:**
   - **SPF:** `v=spf1 include:mx.ovh.com ~all`, as OVHcloud set it up for the mail plan.
   - **DKIM:** enabled for the domain in the OVHcloud Control Panel, which publishes the keys in the
     OVHcloud zone.
   - **DMARC:** `_dmarc` TXT starting at `v=DMARC1; p=none`, with aggregate reports sent to the
     mailbox, and tightened once reports show only aligned mail.
4. **Bounces and limits are handled by hand:**
   - Undeliverable mail comes back to the `no-reply@` mailbox, which the owner reads in webmail.
   - Delivery errors at sending time show in the worker's log and in `messenger:failed:show`
     (ADR 0012).
   - OVHcloud's documented limit is about 200 e-mails an hour per account, which is far above this
     volume.

This supersedes point 12 of ADR 0035 (the provider). ADR 0012's design is unchanged: SMTP, the DSN
only, the queue and the worker.

## Alternatives considered

- **Brevo** (ADR 0035's default). It has bounce and suppression handling, webhooks and 300 e-mails
  a day free. But it is one more provider holding employees' addresses, with its own account, DPA and
  DNS records (a verification code, two DKIM keys, an SPF include), for tens of e-mails a month.
  It remains the first option to move to (see Consequences).
- **Mailgun, Amazon SES, Postmark** (ADR 0012's shortlist). Each is a new account and a US company or
  a paid plan; none is needed at this volume.
- **Our own mail server on the instance.** A fresh cloud IP has no sending reputation, outbound port
  25 needs managing, and SPF, DKIM and blocklist monitoring would all be ours. Rejected.

## Consequences

- **Nothing new in the code or the image:** only `MAILER_DSN` and `MAILER_FROM` per stage
  (`deploy/stage/app.env.example`). Changing provider later means a new DSN and DNS records, no code.
- **No automatic suppression:** an address that keeps bouncing is retried by every new e-mail to it
  until someone fixes it in the app. Acceptable while the owner reads the mailbox.
- **OVHcloud's mail plans are not made for transactional or bulk mail**, and they have hourly limits.
  Move to a transactional provider (Brevo first) when any of these happens:
  - sending approaches the limit, or regularly exceeds about a hundred e-mails a day;
  - deliverability problems show up in DMARC reports or users' spam folders;
  - the app needs bounce webhooks.
- **GDPR:** OVHcloud is already the processor for the host and the domain; no new sub-processor.
- **The mailbox password is a secret** like the others (ADR 0035 point 9). Changing it in the Control
  Panel means updating both stages' `MAILER_DSN`.
