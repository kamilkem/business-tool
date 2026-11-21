<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Problem;

use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds `code` and `parameters` to a problem response (ADR 0028 §5). Both are always present:
 * `code` is null for an error that is not a domain exception, and `parameters` is an object
 * (empty when there are none).
 */
final class CodedErrorNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'coded_error_normalizer_already_called';

    /** @return array<string, mixed> */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CodedError);

        $normalized = $this->normalizer->normalize($data, $format, [self::ALREADY_CALLED => true] + $context);
        \assert(\is_array($normalized));

        $normalized['code'] = $data->errorCode;
        $normalized['parameters'] = [] === $data->errorParameters ? new \ArrayObject() : $data->errorParameters;

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CodedError && !isset($context[self::ALREADY_CALLED]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [CodedError::class => false];
    }
}
