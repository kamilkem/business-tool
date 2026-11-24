<?php

declare(strict_types=1);

namespace Ui\Console\Organization;

use App\Organization\Application\Command\CreateOrganization\CreateOrganizationCommand;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Language\Language;
use App\User\Application\Command\CreateInvitedUser\CreateInvitedUserCommand;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use App\User\Application\Query\GetUserByEmail\GetUserByEmailQuery;
use App\User\Application\Query\GetUserByEmail\UserView;
use App\User\Domain\Exception\PasswordAlreadySetException;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Organization\AddMemberInput;
use Ui\Http\Organization\CreateOrganizationInput;

/**
 * Creates an organization with its first owner, from the host (#219): the way into an empty database
 * and how each new customer starts, since there is no sign-up (ADR 0021). A new owner gets the
 * one-time set-password link of ADR 0013 by e-mail (queued; the worker sends it), and the command
 * prints the same link to pass on when e-mail does not reach them (ADR 0037 §3); an existing user
 * becomes the owner and signs in as before. Validated with the API's own inputs, so the console
 * accepts exactly what the API would.
 */
#[AsCommand(name: 'app:organization:create', description: 'Creates an organization with its first owner and e-mails a new owner the link to set their password')]
final readonly class CreateOrganizationWithOwnerCommand
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private ValidatorInterface $validator,
        private AppLinks $links,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'The owner\'s e-mail; an existing user becomes the owner', name: 'owner-email')]
        ?string $ownerEmail = null,
        #[Option(description: 'The owner\'s first name (a new user only)', name: 'owner-first-name')]
        ?string $ownerFirstName = null,
        #[Option(description: 'The owner\'s last name (a new user only)', name: 'owner-last-name')]
        ?string $ownerLastName = null,
        #[Option(description: 'The new owner\'s language: en or pl (default: the browser\'s)', name: 'owner-language')]
        ?string $ownerLanguage = null,
        #[Option(description: 'The organization\'s name')]
        ?string $name = null,
        #[Option(description: 'Country, ISO 3166-1 alpha-2')]
        string $country = 'PL',
        #[Option(description: 'Currency, ISO 4217')]
        string $currency = 'PLN',
        #[Option(description: 'Time zone, IANA')]
        string $timezone = 'Europe/Warsaw',
        #[Option(description: 'Tax ID (NIP in Poland), needed for KSeF', name: 'tax-id')]
        ?string $taxId = null,
    ): int {
        $interactive = $input->isInteractive();
        $ownerEmail = $this->value($io, $interactive, $ownerEmail, 'Owner\'s e-mail');
        if (null === $ownerEmail) {
            return $this->missing($io, 'owner-email');
        }

        /** @var UserView|null $existing */
        $existing = $this->queryBus->ask(new GetUserByEmailQuery($ownerEmail));
        if (null === $existing) {
            $ownerFirstName = $this->value($io, $interactive, $ownerFirstName, 'Owner\'s first name');
            $ownerLastName = $this->value($io, $interactive, $ownerLastName, 'Owner\'s last name');
            if (null === $ownerFirstName || null === $ownerLastName) {
                return $this->missing($io, 'owner-first-name and --owner-last-name');
            }
        }
        $name = $this->value($io, $interactive, $name, 'Organization\'s name');
        if (null === $name) {
            return $this->missing($io, 'name');
        }

        $language = null === $ownerLanguage ? null : Language::tryFrom($ownerLanguage);
        if (null !== $ownerLanguage && null === $language) {
            $io->error(\sprintf('--owner-language is "en" or "pl", not "%s".', $ownerLanguage));

            return Command::INVALID;
        }
        $taxId = null === $taxId || '' === trim($taxId) ? null : trim($taxId);

        // The API's inputs carry the rules (CLAUDE.md: validation happens only in the Ui inputs).
        $owner = new AddMemberInput(
            $ownerEmail,
            $existing->firstName ?? (string) $ownerFirstName,
            $existing->lastName ?? (string) $ownerLastName,
            MembershipRole::Owner,
            $language,
        );
        $organization = new CreateOrganizationInput(trim($name), $taxId, strtoupper(trim($country)), strtoupper(trim($currency)), trim($timezone));
        $violations = [...$this->messages($this->validator->validate($owner), 'owner'), ...$this->messages($this->validator->validate($organization), 'organization')];
        if ([] !== $violations) {
            $io->error(['The organization was not created:', ...$violations]);

            return Command::INVALID;
        }

        $io->definitionList(
            ['Organization' => $organization->name],
            ['Country, currency, time zone' => \sprintf('%s, %s, %s', $organization->countryCode, $organization->currency, $organization->timezone)],
            ['Tax ID' => $organization->taxId ?? '(none)'],
            ['Owner' => \sprintf('%s %s <%s>', $owner->firstName, $owner->lastName, trim($owner->email))],
            ['Owner\'s account' => null === $existing ? 'new: gets an e-mail with a link to set their password' : 'existing user'],
        );
        if ($interactive && !$io->confirm('Create it?', false)) {
            $io->warning('Nothing was created.');

            return Command::FAILURE;
        }

        $ownerId = $existing->id ?? Uuid::v7()->toRfc4122();
        if (null === $existing) {
            $this->commandBus->handle(new CreateInvitedUserCommand($ownerId, trim($owner->email), trim($owner->firstName), trim($owner->lastName), $language?->value));
        }
        $organizationId = Uuid::v7()->toRfc4122();
        $this->commandBus->handle(new CreateOrganizationCommand(
            organizationId: $organizationId,
            ownerId: $ownerId,
            name: $organization->name,
            taxId: $organization->taxId,
            countryCode: $organization->countryCode,
            currency: $organization->currency,
            timezone: $organization->timezone,
        ));

        // The Ui generates the secret so the same link can be e-mailed and printed (ADR 0037 §3–4).
        $secret = PasswordTokenSecret::generate();
        $link = null;
        try {
            $this->commandBus->handle(new SendInvitationCommand(Uuid::v7()->toRfc4122(), $ownerId, $organizationId, MembershipRole::Owner->value, null, $secret->value));
            $link = $this->links->setPassword(AppLinks::appFor(MembershipRole::Owner), $secret);
            $access = \sprintf('E-mailed %s a link to set their password (queued; the worker sends it).', trim($owner->email));
        } catch (PasswordAlreadySetException) {
            $access = \sprintf('%s already has a password and signs in to the admin as before.', trim($owner->email));
        }

        $io->success([
            \sprintf('Created the organization "%s" (%s) with %s as its owner.', $organization->name, $organizationId, trim($owner->email)),
            $access,
        ]);
        if (null !== $link) {
            $io->writeln(\sprintf('If the e-mail does not arrive, pass this link to the owner only (it works once, for %d days, and sets their password):', PasswordTokenPurpose::Invitation->lifetime()->d));
            $io->writeln($link);
        }

        return Command::SUCCESS;
    }

    private function value(SymfonyStyle $io, bool $interactive, ?string $value, string $question): ?string
    {
        if (null !== $value && '' !== trim($value)) {
            return $value;
        }
        if (!$interactive) {
            return null;
        }
        $answer = $io->ask($question);

        return \is_string($answer) && '' !== trim($answer) ? $answer : null;
    }

    private function missing(SymfonyStyle $io, string $option): int
    {
        $io->error(\sprintf('Missing --%s (or run it interactively).', $option));

        return Command::INVALID;
    }

    /** @return list<string> */
    private function messages(ConstraintViolationListInterface $violations, string $prefix): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = \sprintf('%s.%s: %s', $prefix, $violation->getPropertyPath(), $violation->getMessage());
        }

        return $messages;
    }
}
