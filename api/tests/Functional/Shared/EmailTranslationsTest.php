<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Domain\Language\Language;
use App\Shared\Infrastructure\Mail\SymfonyTransactionalMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * E-mails are in the recipient's language (ADR 0028 §6): every language has exactly the English
 * e-mail texts, every subject key used in src/ exists, and every template renders in every language
 * with no key left untranslated. `make translations-check` checks the keys used in the templates too.
 */
final class EmailTranslationsTest extends KernelTestCase
{
    private const string DOMAIN = SymfonyTransactionalMailer::DOMAIN;
    private const string SUBJECT_PATTERN = "/'([a-z_]+\\.subject[a-z_]*)'/";

    /**
     * Contexts for every template in templates/email/, together covering each of its branches.
     *
     * @var array<string, list<array<string, scalar|null>>>
     */
    private const array CONTEXTS = [
        'added_to_organization' => [
            ['firstName' => 'Eve', 'organizationName' => 'Corner Pub', 'addedByName' => 'Olivia Owner', 'link' => 'https://example.com/login'],
        ],
        'invitation' => [
            ['firstName' => 'Eve', 'organizationName' => 'Corner Pub', 'inviterName' => 'Olivia Owner', 'link' => 'https://example.com/set-password', 'validForDays' => 7],
        ],
        'password_changed' => [['firstName' => 'Eve'], ['firstName' => '']],
        'password_reset' => [
            ['firstName' => 'Eve', 'link' => 'https://example.com/set-password', 'validForMinutes' => 60],
            ['firstName' => '', 'link' => 'https://example.com/set-password', 'validForMinutes' => 1],
        ],
        'shift_change_request_decided' => [
            self::DECIDED + ['approved' => true, 'kind' => 'missing_shift', 'endedAt' => 'Fri 13 Nov 2026, 00:30', 'rejectionReason' => null],
            self::DECIDED + ['approved' => true, 'kind' => 'new_times', 'endedAt' => null, 'rejectionReason' => null],
            self::DECIDED + ['approved' => false, 'kind' => 'new_times', 'endedAt' => null, 'rejectionReason' => 'You were off'],
        ],
        'shift_change_request_submitted' => [
            self::SUBMITTED + ['kind' => 'missing_shift', 'endedAt' => 'Fri 13 Nov 2026, 00:30', 'shiftStartedAt' => null, 'shiftEndedAt' => null],
            self::SUBMITTED + ['kind' => 'new_times', 'endedAt' => null, 'shiftStartedAt' => null, 'shiftEndedAt' => null],
        ],
    ];

    private const array DECIDED = ['firstName' => 'Eve', 'organizationName' => 'Corner Pub', 'deciderName' => 'Mark Manager', 'startedAt' => 'Thu 12 Nov 2026, 17:00', 'note' => 'Forgot to clock out', 'timeZone' => 'Europe/Warsaw', 'link' => 'https://example.com/'];
    private const array SUBMITTED = ['firstName' => 'Mark', 'requesterName' => 'Eve Nowak', 'organizationName' => 'Corner Pub', 'startedAt' => 'Thu 12 Nov 2026, 17:00', 'note' => 'Forgot to clock out', 'timeZone' => 'Europe/Warsaw', 'link' => 'https://example.com/'];

    public function test_it_has_exactly_the_english_email_texts_in_every_language(): void
    {
        $english = $this->keys(Language::English);
        self::assertNotEmpty($english);

        foreach (Language::cases() as $language) {
            self::assertSame($english, $this->keys($language), 'E-mail texts in "'.$language->value.'"');
        }
    }

    public function test_it_has_every_subject_key_used_in_src(): void
    {
        $subjects = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/src/App')->name('*.php') as $file) {
            preg_match_all(self::SUBJECT_PATTERN, $file->getContents(), $matches);
            array_push($subjects, ...$matches[1]);
        }

        self::assertContains('shift_change_request_decided.subject_rejected', $subjects);
        self::assertSame([], array_values(array_diff($subjects, $this->keys(Language::English))));
    }

    public function test_it_has_a_context_for_every_template(): void
    {
        $templates = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/templates/email')->name('*.html.twig') as $file) {
            $templates[] = $file->getBasename('.html.twig');
        }
        sort($templates);

        self::assertSame($templates, array_keys(self::CONTEXTS));
    }

    public function test_it_renders_every_template_in_every_language_with_no_key_left(): void
    {
        $twig = static::getContainer()->get(Environment::class);
        $localeSwitcher = static::getContainer()->get(LocaleSwitcher::class);
        $keys = $this->keys(Language::English);

        foreach (self::CONTEXTS as $template => $contexts) {
            foreach ($contexts as $case => $context) {
                $rendered = [];
                foreach (Language::cases() as $language) {
                    $html = $localeSwitcher->runWithLocale(
                        $language->value,
                        static fn (): string => $twig->render('email/'.$template.'.html.twig', $context),
                    );
                    foreach ($keys as $key) {
                        self::assertStringNotContainsString($key, $html, $template.' #'.$case.' in "'.$language->value.'"');
                    }
                    $rendered[$language->value] = $html;
                }
                self::assertCount(\count($rendered), array_unique($rendered), $template.' #'.$case.' reads the same in two languages');
            }
        }
    }

    /** @return list<string> sorted keys of the `email` domain defined in this language itself, not by fallback */
    private function keys(Language $language): array
    {
        $keys = array_keys(static::getContainer()->get('translator')->getCatalogue($language->value)->all(self::DOMAIN));
        sort($keys);

        return $keys;
    }
}
