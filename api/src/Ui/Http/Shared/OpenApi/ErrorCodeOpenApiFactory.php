<?php

declare(strict_types=1);

namespace Ui\Http\Shared\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Ui\Http\Shared\Problem\ErrorCodes;

/**
 * Documents the `code` and `parameters` of problem responses (ADR 0028 §5, `CodedErrorNormalizer`):
 * `ErrorCode` enumerates every code, so the clients' generated types (`make types`) know them.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ErrorCodeOpenApiFactory implements OpenApiFactoryInterface
{
    private const array ERROR_SCHEMAS = ['Error', 'Error.jsonld'];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
        private ErrorCodes $errorCodes,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['ErrorCode'] = new \ArrayObject([
            'type' => 'string',
            'description' => 'The stable code of a domain error: `<module>.<error>[.<variant>]`. Clients translate it with its parameters.',
            'enum' => $this->errorCodes->all(),
        ]);

        $properties = [
            'code' => [
                'description' => 'The domain error\'s code, or null for any other error: translate it, else fall back to a message for the status.',
                'anyOf' => [['$ref' => '#/components/schemas/ErrorCode'], ['type' => 'null']],
            ],
            'parameters' => [
                'description' => 'The values the translation of the code puts in: ids, names, counts, dates (Y-m-d), instants (ISO 8601). Empty for an error without a code.',
                'type' => 'object',
                'additionalProperties' => ['type' => ['string', 'integer', 'boolean', 'null']],
            ],
        ];

        foreach (self::ERROR_SCHEMAS as $name) {
            if (!isset($schemas[$name])) {
                continue;
            }
            $schemas[$name] = self::withProperties(self::toArray($schemas[$name]), $properties);
        }

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    /**
     * @param array<string, mixed>                $schema
     * @param array<string, array<string, mixed>> $properties
     *
     * @return \ArrayObject<string, mixed>
     */
    private static function withProperties(array $schema, array $properties): \ArrayObject
    {
        if (isset($schema['allOf']) && \is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $i => $part) {
                $part = self::toArray($part);
                if (isset($part['properties'])) {
                    $schema['allOf'][$i] = self::withProperties($part, $properties)->getArrayCopy();
                }
            }

            return new \ArrayObject($schema);
        }

        $schema['properties'] = self::toArray($schema['properties'] ?? []) + $properties;
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $schema['required'] = array_values(array_unique([...$required, ...array_keys($properties)]));

        return new \ArrayObject($schema);
    }

    /** @return array<string, mixed> */
    private static function toArray(mixed $value): array
    {
        $array = $value instanceof \ArrayObject ? $value->getArrayCopy() : (array) $value;

        return array_map(static fn (mixed $item): mixed => $item instanceof \ArrayObject ? $item->getArrayCopy() : $item, $array);
    }
}
