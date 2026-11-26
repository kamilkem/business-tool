<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Anthropic;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogProduct;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\ItemToInterpret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The item interpreter on the Claude API (ADR 0042 §3): one request to `/v1/messages` per batch of
 * items, with the organization's catalog in a cached system block and the answers as structured
 * output (`output_config.format`, a JSON schema), so the reply is always the shape the schema says.
 *
 * Sent: the catalog's product ids, names, measures and category names, and each item's name, unit and
 * seller code. Never prices, quantities, dates, invoice numbers or seller names (ADR 0042 §6).
 *
 * No API key configured: not available, and never called. Anything that is not an answer (a transport
 * error, a status other than 200, a refusal, a cut-off or unreadable reply) is an
 * ItemInterpreterUnavailableException with the reason and no body, so nothing the provider sent back
 * reaches a log. Tests and CI use an in-memory interpreter or a MockHttpClient with fixtures
 * (tests/Fixtures/Anthropic) and never reach the network.
 */
final readonly class AnthropicItemInterpreter implements ItemInterpreterInterface
{
    public const int MAX_TOKENS = 8192;

    /** The model's answer for "none" and "unknown": strict structured output has no optional fields, so empty strings say it. */
    private const string NONE = '';

    public function __construct(
        /** The scoped client of config/packages/http_client.yaml: base URI, the API key and version headers. */
        private HttpClientInterface $anthropicClient,
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'ITEM_MATCHING_MODEL')]
        private string $model,
    ) {
    }

    public function isAvailable(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function interpret(array $catalog, array $items): array
    {
        if (!$this->isAvailable()) {
            throw ItemInterpreterUnavailableException::because('no API key is configured');
        }

        try {
            $response = $this->anthropicClient->request('POST', '/v1/messages', ['json' => $this->requestBody($catalog, $items)]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw ItemInterpreterUnavailableException::because(\sprintf('the API answered %d%s', $status, $this->errorType($response)));
            }
            /** @var array<string, mixed> $body */
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw ItemInterpreterUnavailableException::because('the request failed ('.$e::class.')', $e);
        }

        return $this->answersIn($body, $catalog, $items);
    }

    /**
     * @param non-empty-list<CatalogProduct>  $catalog
     * @param non-empty-list<ItemToInterpret> $items
     *
     * @return array<string, mixed>
     */
    private function requestBody(array $catalog, array $items): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            // The instructions and the catalog are the same for every batch of a run: cached.
            'system' => [[
                'type' => 'text',
                'text' => self::instructions().$this->catalogText($catalog),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => "Items to match, one JSON object per line:\n".implode("\n", array_map(
                    static fn (ItemToInterpret $item): string => json_encode(
                        ['key' => $item->key, 'name' => $item->name, 'unit' => $item->unit ?? '', 'sellerCode' => $item->sellerCode ?? ''],
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
                    ),
                    $items,
                )),
            ]],
        ];
    }

    /** @param non-empty-list<CatalogProduct> $catalog */
    private function catalogText(array $catalog): string
    {
        return implode("\n", array_map(
            static fn (CatalogProduct $product): string => \sprintf('%s | %s | %s | %s', $product->id, $product->name, $product->measure, $product->categoryName ?? '-'),
            $catalog,
        ));
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
            You match lines of purchase invoices from a hospitality business (a bar or restaurant, mostly in Poland) to the products of its catalog, and read each line's package size.

            For each item given by the user, answer with:
            - productId: the id of the catalog product the line is, copied exactly from the catalog below, or "" when no catalog product is it. Never invent an id and never answer with a name.
            - confidence: "high" when you are sure it is that product, "medium" when it is likely, "low" when you are guessing. Use "high" only when brand, kind and flavour all agree.
            - packageSize: how many base units one UNIT of the item's quantity holds, as a decimal number in a string with a point ("0.7", "3", "1"), or "" when you cannot tell.

            Rules:
            - The same product is written many ways: case, diacritics, abbreviations ("J.D.", "WH."), word order, extra words ("40%", promo text) and different package sizes. One product with several sizes is one catalog product.
            - A flavour, age or kind that differs is a different product ("Jack Daniel's Honey" is not "Jack Daniel's"; "Coca-Cola Zero" is not "Coca-Cola"). When the catalog has no such product, answer "".
            - Base units are litres for a product measured in volume, kilograms for mass, pieces for count. The measure is in the catalog line.
            - The package size is per one unit of the quantity: 6 `szt.` of "Whisky 0,7l" have package size 0.7; a `zgrz.` or `kart.` of "6x0,5L" has 3 (the whole pack); 30 `l` of draught beer have 1; 12.35 `kg` of pork have 1. When the unit is itself a measure (l, ml, kg, g), the package size is that unit in base units (1 for l and kg) whatever the name says. Convert ml, cl, dl, hl, g, dkg. Never use a number that is not followed by a unit (0,7 alone, 40%, "No.7", years) as a size.
            - A unit that is a case, carton or pack (CO, kart., op., zgrz., BOX, or any word of the seller's) holds a number of pieces that only the text can tell: when the name or unit does not say how many ("6x", "x6", "24 szt"), the package size is "" and never your guess. Only szt., but., fl. and the measures l, ml, kg, g count one piece or one unit of the measure.
            - For a product that is not goods (rent, electricity, deposit, delivery) the package size is "".
            - Answer for every item, with the key it was given.

            Catalog, one product per line: id | name | measure | category
            TEXT."\n";
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['answers'],
            'properties' => [
                'answers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'productId', 'confidence', 'packageSize'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'productId' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'packageSize' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>            $body
     * @param non-empty-list<CatalogProduct>  $catalog
     * @param non-empty-list<ItemToInterpret> $items
     *
     * @return list<ItemInterpretation>
     */
    private function answersIn(array $body, array $catalog, array $items): array
    {
        $stop = \is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : '';
        if ('end_turn' !== $stop) {
            throw ItemInterpreterUnavailableException::because(\sprintf('the reply ended with "%s"', $stop));
        }

        $text = null;
        foreach (\is_array($body['content'] ?? null) ? $body['content'] : [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }
        $decoded = null === $text ? null : json_decode($text, true);
        if (!\is_array($decoded) || !\is_array($decoded['answers'] ?? null)) {
            throw ItemInterpreterUnavailableException::because('the reply is not the expected JSON');
        }

        $keys = array_flip(array_map(static fn (ItemToInterpret $item): string => $item->key, $items));
        $productIds = array_flip(array_map(static fn (CatalogProduct $product): string => $product->id, $catalog));

        $answers = [];
        foreach ($decoded['answers'] as $answer) {
            if (!\is_array($answer) || !\is_string($answer['key'] ?? null) || !isset($keys[$answer['key']])) {
                continue;
            }
            $productId = \is_string($answer['productId'] ?? null) && isset($productIds[$answer['productId']]) ? $answer['productId'] : null;
            $size = \is_string($answer['packageSize'] ?? null) && self::NONE !== trim($answer['packageSize']) ? trim($answer['packageSize']) : null;
            $answers[$answer['key']] = new ItemInterpretation(
                $answer['key'],
                $productId,
                InterpretationConfidence::tryFrom(\is_string($answer['confidence'] ?? null) ? $answer['confidence'] : '') ?? InterpretationConfidence::Low,
                $size,
            );
        }

        return array_values($answers);
    }

    /** The provider's error type (`rate_limit_error`), when the reply has one in the documented shape; nothing else of it is kept. */
    private function errorType(ResponseInterface $response): string
    {
        try {
            $body = $response->toArray(false);
        } catch (ExceptionInterface) {
            return '';
        }
        $error = $body['error'] ?? null;
        $type = \is_array($error) && \is_string($error['type'] ?? null) ? $error['type'] : null;

        return null === $type || 1 !== preg_match('/^[a-z_]{1,64}$/', $type) ? '' : ' ('.$type.')';
    }
}
