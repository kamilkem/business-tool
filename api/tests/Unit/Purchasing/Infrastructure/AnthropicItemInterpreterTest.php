<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Infrastructure;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogProduct;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemToInterpret;
use App\Purchasing\Infrastructure\Anthropic\AnthropicItemInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The adapter on the Claude API, against recorded-shape responses in tests/Fixtures/Anthropic: no network (adr/0042 §3). */
#[CoversClass(AnthropicItemInterpreter::class)]
final class AnthropicItemInterpreterTest extends TestCase
{
    private const string JACK = '018f0000-0000-7000-8000-000000000001';
    private const string HONEY = '018f0000-0000-7000-8000-000000000002';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function test_it_is_unavailable_without_an_api_key_and_never_calls(): void
    {
        $interpreter = $this->interpreter('', new MockResponse('{}'));

        self::assertFalse($interpreter->isAvailable());
        $this->expectException(ItemInterpreterUnavailableException::class);
        try {
            $interpreter->interpret($this->catalog(), $this->items());
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    public function test_it_reads_the_answers_of_the_model(): void
    {
        $interpreter = $this->interpreter('sk-test', $this->fixture('messages-answers.json'));

        $answers = $interpreter->interpret($this->catalog(), $this->items());

        self::assertTrue($interpreter->isAvailable());
        $byKey = [];
        foreach ($answers as $answer) {
            $byKey[$answer->key] = $answer;
        }
        self::assertSame([self::JACK, InterpretationConfidence::High, '0.7'], [$byKey['k-jack']->productId, $byKey['k-jack']->confidence, $byKey['k-jack']->packageSize]);
        self::assertSame([self::HONEY, InterpretationConfidence::Medium, null], [$byKey['k-honey']->productId, $byKey['k-honey']->confidence, $byKey['k-honey']->packageSize]);
        self::assertSame([null, InterpretationConfidence::Low, null], [$byKey['k-rent']->productId, $byKey['k-rent']->confidence, $byKey['k-rent']->packageSize]);
        self::assertArrayNotHasKey('k-unknown-key', $byKey, 'An answer for a key that was not asked is dropped.');
        self::assertNull($byKey['k-foreign-product']->productId, 'A product id that is not in the catalog is no answer.');
    }

    public function test_it_sends_the_catalog_and_the_items_and_nothing_else(): void
    {
        $this->interpreter('sk-test', $this->fixture('messages-answers.json'))->interpret($this->catalog(), $this->items());

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.anthropic.test/v1/messages', $this->requests[0]['url']);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->requests[0]['options']['body'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('claude-haiku-5-5', $body['model']);
        self::assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $body['output_config']['format']['schema']]], $body['output_config']);
        self::assertSame(['answers'], $body['output_config']['format']['schema']['required']);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
        self::assertStringContainsString(self::JACK.' | Jack Daniel\'s | volume | Whisky', $body['system'][0]['text']);
        self::assertStringContainsString('Czynsz | none | -', $body['system'][0]['text']);

        $user = $body['messages'][0]['content'];
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertStringContainsString('{"key":"k-jack","name":"JACK DANIELS 0,7L 40%","unit":"szt.","sellerCode":"JD-07"}', $user);
        self::assertStringContainsString('{"key":"k-rent","name":"Czynsz najmu lokalu","unit":"","sellerCode":""}', $user);
        // Only the item's name, unit and code, and the catalog's names: no price, quantity, seller or date.
        self::assertSame(4, substr_count($user, '"key"'));
        self::assertStringNotContainsString('Hurtownia', $user.$body['system'][0]['text']);
    }

    /** @return iterable<string, array{MockResponse|string, string}> */
    public static function failures(): iterable
    {
        yield 'rate limited' => [new MockResponse(self::read('error-429.json'), ['http_code' => 429]), 'the API answered 429 (rate_limit_error)'];
        yield 'rejected credentials' => [new MockResponse(self::read('error-401.json'), ['http_code' => 401]), 'the API answered 401 (authentication_error)'];
        yield 'down, with an HTML page' => [new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]), 'the API answered 502'];
        yield 'a refusal' => [new MockResponse(self::read('messages-refusal.json')), 'the reply ended with "refusal"'];
        yield 'cut off' => [new MockResponse(self::read('messages-max-tokens.json')), 'the reply ended with "max_tokens"'];
        yield 'not the expected JSON' => [new MockResponse(self::read('messages-not-json.json')), 'the reply is not the expected JSON'];
        yield 'a transport error' => [new MockResponse('', ['error' => 'Connection timed out']), 'the request failed'];
    }

    #[DataProvider('failures')]
    public function test_it_says_unavailable_with_the_reason_and_nothing_the_provider_sent(MockResponse $response, string $reason): void
    {
        $interpreter = $this->interpreter('sk-test', $response);

        try {
            $interpreter->interpret($this->catalog(), $this->items());
            self::fail('The model could not answer.');
        } catch (ItemInterpreterUnavailableException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringNotContainsString('exceed your organization', $e->getMessage());
            self::assertStringNotContainsString('Bad gateway', $e->getMessage());
        }
    }

    private function interpreter(string $apiKey, MockResponse $response): AnthropicItemInterpreter
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        }, 'https://api.anthropic.test');

        return new AnthropicItemInterpreter($client, $apiKey, 'claude-haiku-5-5');
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse(self::read($name), ['response_headers' => ['content-type: application/json']]);
    }

    private static function read(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Anthropic/'.$name);
    }

    /** @return non-empty-list<CatalogProduct> */
    private function catalog(): array
    {
        return [
            new CatalogProduct(self::JACK, 'Jack Daniel\'s', 'volume', 'Whisky'),
            new CatalogProduct(self::HONEY, 'Jack Daniel\'s Honey', 'volume', 'Whisky'),
            new CatalogProduct('018f0000-0000-7000-8000-000000000003', 'Czynsz', 'none', null),
        ];
    }

    /** @return non-empty-list<ItemToInterpret> */
    private function items(): array
    {
        return [
            new ItemToInterpret('k-jack', 'JACK DANIELS 0,7L 40%', 'szt.', 'JD-07'),
            new ItemToInterpret('k-honey', 'Jack Daniel\'s Honey 0,7', 'szt.', null),
            new ItemToInterpret('k-rent', 'Czynsz najmu lokalu', null, null),
            new ItemToInterpret('k-foreign-product', 'Cos 1l', 'szt.', null),
        ];
    }
}
