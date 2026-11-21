<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Domain\Language\Language;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every constraint message we write in src/ (a `message:` argument, a `buildViolation()` text or a
 * `MESSAGE` constant) has a translation in every language besides English, the source (ADR 0028 §5).
 */
final class ValidatorTranslationsTest extends KernelTestCase
{
    private const string MESSAGE_PATTERN = "/(?:\\bmessage: |buildViolation\\(|\\bMESSAGE = )'((?:[^'\\\\]|\\\\.)+)'/";

    public function test_it_finds_our_own_constraint_messages(): void
    {
        self::assertContains('The period must not be longer than {{ limit }} days.', self::ownMessages());
    }

    public function test_it_translates_every_own_constraint_message_into_every_language(): void
    {
        $translator = static::getContainer()->get('translator');

        $missing = [];
        foreach (Language::cases() as $language) {
            if (Language::English === $language) {
                continue;
            }
            $catalogue = $translator->getCatalogue($language->value);
            foreach (self::ownMessages() as $message) {
                if (!$catalogue->defines($message, 'validators')) {
                    $missing[] = $language->value.': '.$message;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function test_it_enables_exactly_the_languages_of_the_enum(): void
    {
        self::assertSame(
            array_map(static fn (Language $language): string => $language->value, Language::cases()),
            static::getContainer()->getParameter('kernel.enabled_locales'),
        );
    }

    /** @return list<string> */
    private static function ownMessages(): array
    {
        $messages = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            preg_match_all(self::MESSAGE_PATTERN, $file->getContents(), $matches);
            foreach ($matches[1] as $message) {
                $messages[] = stripslashes($message);
            }
        }

        return array_values(array_unique($messages));
    }
}
