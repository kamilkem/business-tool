<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Anthropic;

use App\Purchasing\Domain\Exception\ItemInterpreterUnavailableException;
use App\Purchasing\Domain\Matching\CatalogCategory;
use App\Purchasing\Domain\Matching\CatalogProduct;
use App\Purchasing\Domain\Matching\InterpretationConfidence;
use App\Purchasing\Domain\Matching\ItemInterpretation;
use App\Purchasing\Domain\Matching\ItemInterpreterInterface;
use App\Purchasing\Domain\Matching\ItemToInterpret;
use App\Purchasing\Domain\Matching\ProductProposal;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The item interpreter on the Claude API (ADR 0042 §3): one request to `/v1/messages` per batch of
 * items, with the organization's catalog in a cached system block and the answers as structured
 * output (`output_config.format`, a JSON schema), so the reply is always the shape the schema says.
 *
 * Sent: the catalog's product ids, names, measures and category names, the category tree as ids and paths
 * of names, and each item's name, unit and seller code. Never prices, quantities, dates, invoice numbers
 * or seller names (ADR 0042 §6, ADR 0044). For an item no product is, it also asks for a proposed product:
 * a name, a measure and a category of the tree by id (ADR 0044).
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

    public function interpret(array $catalog, array $categories, array $items): array
    {
        if (!$this->isAvailable()) {
            throw ItemInterpreterUnavailableException::because('no API key is configured');
        }

        try {
            $response = $this->anthropicClient->request('POST', '/v1/messages', ['json' => $this->requestBody($catalog, $categories, $items)]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw ItemInterpreterUnavailableException::because(\sprintf('the API answered %d%s', $status, $this->errorType($response)));
            }
            /** @var array<string, mixed> $body */
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw ItemInterpreterUnavailableException::because('the request failed ('.$e::class.')', $e);
        }

        return $this->answersIn($body, $catalog, $categories, $items);
    }

    /**
     * @param list<CatalogProduct>            $catalog
     * @param list<CatalogCategory>           $categories
     * @param non-empty-list<ItemToInterpret> $items
     *
     * @return array<string, mixed>
     */
    private function requestBody(array $catalog, array $categories, array $items): array
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
                'text' => self::instructions().$this->catalogText($catalog).self::categoriesHeading().$this->categoriesText($categories),
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

    /** @param list<CatalogProduct> $catalog */
    private function catalogText(array $catalog): string
    {
        return [] === $catalog ? '(no products yet)' : implode("\n", array_map(
            static fn (CatalogProduct $product): string => \sprintf('%s | %s | %s | %s', $product->id, $product->name, $product->measure, $product->categoryName ?? '-'),
            $catalog,
        ));
    }

    private static function categoriesHeading(): string
    {
        return "\n\nCategories, one per line: id | path\n";
    }

    /** @param list<CatalogCategory> $categories */
    private function categoriesText(array $categories): string
    {
        return [] === $categories ? '(no categories)' : implode("\n", array_map(
            static fn (CatalogCategory $category): string => $category->id.' | '.$category->path,
            $categories,
        ));
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
            You match lines of purchase invoices from a hospitality business (a bar or restaurant, mostly in Poland) to the products of its catalog, and read each line's package size.

            For each item given by the user, answer with:
            - productId: the id of the catalog product the line is, copied exactly from the catalog below, or "" when no catalog product is it. Never invent an id and never answer with a name.
            - confidence: "high" when you are sure it is that product, "medium" when it is likely, "low" when you are guessing. Use "high" only when brand, kind, flavour and alcohol strength all agree.
            - packageSize: how many base units one UNIT of the item's quantity holds, as a decimal number in a string with a point ("0.7", "3", "1"), or "" when you cannot tell.

            Rules:
            - The same product is written many ways: case, diacritics, abbreviations ("J.D.", "WH."), word order, extra words (promo text) and different package sizes; the alcohol strength may be printed differently ("40%", "40 %", "40% vol", "40,0%"), and that is the same strength. One product with several sizes is one catalog product.
            - A flavour, age or kind that differs is a different product ("Jack Daniel's Honey" is not "Jack Daniel's"; "Coca-Cola Zero" is not "Coca-Cola"). When the catalog has no such product, answer "".
            - A different alcohol strength is a different product: a beer of 4% is not the same product as one of 0,5% ("Litovel Black Lemon 4%" is not "Litovel Black Lemon 0,5%"), a vodka of 37,5% is not one of 40%. When the line and a catalog product both state a strength and they differ, that product is not it. A vintage ("2015") or an age ("12 YO") that differs is a different product too. A line that states no strength can still be a product whose name states one, when brand, kind and flavour agree, but then the confidence is at most "medium".
            - Base units are litres for a product measured in volume, kilograms for mass, pieces for count. The measure is in the catalog line.
            - The package size is per one unit of the quantity: 6 `szt.` of "Whisky 0,7l" have package size 0.7; a `zgrz.` or `kart.` of "6x0,5L" has 3 (the whole pack); 30 `l` of draught beer have 1; 12.35 `kg` of pork have 1. When the unit is itself a measure (l, ml, kg, g), the package size is that unit in base units (1 for l and kg) whatever the name says. Convert ml, cl, dl, hl, g, dkg. Never use a number that is not followed by a unit (0,7 alone, 40%, "No.7", years) as a size.
            - A unit that is a case, carton or pack (CO, kart., op., zgrz., BOX, or any word of the seller's) holds a number of pieces that only the text can tell: when the name or unit does not say how many ("6x", "x6", "24 szt"), the package size is "" and never your guess. Only szt., but., fl. and the measures l, ml, kg, g count one piece or one unit of the measure.
            - For a product that is not goods (rent, electricity, deposit, delivery) the package size is "".
            - Answer for every item, with the key it was given.

            Proposing a product: when no catalog product is the item (productId ""), propose the product to create for it; otherwise leave all three fields below "".
            - proposedName: the short, generic name of the product the line is: brand and kind, as the owner would write it in a list ("Jack Daniel's", "Coca-Cola Zero", "Czynsz"), without package size, pack words (szt., kart.), seller codes or promo text, but with the alcohol strength when the line states one ("Litovel Black Lemon 4%"), written as a number and "%" ("0,5%"), because a different strength is a different product. One name for every spelling and size of the product, so the same product always gets the same name. Use the language of the catalog's names when it has any. Never the name of a catalog product: when the line is that product, answer its productId instead.
            - proposedMeasure: "volume" for drinks and other liquids sold by volume, "mass" for what is sold by weight, "count" for what is counted in pieces, "none" for what is not goods (rent, electricity, deposit, delivery, services).
            - proposedCategoryId: the id of the one category of the list below that fits best, copied exactly, or "" when none fits. Never invent an id or a category.

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
                        'required' => ['key', 'productId', 'confidence', 'packageSize', 'proposedName', 'proposedMeasure', 'proposedCategoryId'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'productId' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'packageSize' => ['type' => 'string'],
                            'proposedName' => ['type' => 'string'],
                            'proposedMeasure' => ['type' => 'string', 'enum' => ['', ...array_map(static fn (ProductMeasure $measure): string => $measure->value, ProductMeasure::cases())]],
                            'proposedCategoryId' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>            $body
     * @param list<CatalogProduct>            $catalog
     * @param list<CatalogCategory>           $categories
     * @param non-empty-list<ItemToInterpret> $items
     *
     * @return list<ItemInterpretation>
     */
    private function answersIn(array $body, array $catalog, array $categories, array $items): array
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
        $categoryIds = array_flip(array_map(static fn (CatalogCategory $category): string => $category->id, $categories));

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
                null === $productId ? self::proposalIn($answer, $categoryIds) : null,
            );
        }

        return array_values($answers);
    }

    /**
     * The proposal of an answer: only with a name and a measure that are valid, and a category that is one
     * of the organization's; a category that is not (an invented id) is dropped, the rest stays.
     *
     * @param array<mixed>       $answer
     * @param array<string, int> $categoryIds the ids of the categories that were sent, as keys
     */
    private static function proposalIn(array $answer, array $categoryIds): ?ProductProposal
    {
        $name = \is_string($answer['proposedName'] ?? null) ? trim($answer['proposedName']) : '';
        $measure = \is_string($answer['proposedMeasure'] ?? null) ? ProductMeasure::tryFrom($answer['proposedMeasure']) : null;
        if ('' === $name || mb_strlen($name) > Product::NAME_MAX_LENGTH || null === $measure) {
            return null;
        }
        $categoryId = \is_string($answer['proposedCategoryId'] ?? null) && isset($categoryIds[$answer['proposedCategoryId']]) ? $answer['proposedCategoryId'] : null;

        return new ProductProposal($name, $measure, $categoryId);
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
