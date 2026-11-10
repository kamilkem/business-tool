<?php

declare(strict_types=1);

namespace Ui\Http\Shared\OpenApi;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Derives "required" in the OpenAPI schema from how our Resources and Inputs are written, so the
 * generated TypeScript types are exact: a property is required unless it has a default value
 * (a property default or a promoted constructor parameter default).
 *
 *  - Resources (`final readonly`, constructor with no defaults): every field always present.
 *  - Inputs: a field with a default may be omitted; one without must be sent.
 */
#[AsDecorator('api_platform.metadata.property.metadata_factory', priority: -100)]
final readonly class RequiredPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PropertyMetadataFactoryInterface $decorated,
    ) {
    }

    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->decorated->create($resourceClass, $property, $options);

        if (!str_starts_with($resourceClass, 'Ui\\') || !class_exists($resourceClass) || !property_exists($resourceClass, $property)) {
            return $metadata;
        }

        return $metadata->withRequired(!self::hasDefault(new \ReflectionProperty($resourceClass, $property)));
    }

    private static function hasDefault(\ReflectionProperty $property): bool
    {
        if (!$property->isPromoted()) {
            return $property->hasDefaultValue();
        }

        foreach ($property->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property->getName()) {
                return $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }
}
