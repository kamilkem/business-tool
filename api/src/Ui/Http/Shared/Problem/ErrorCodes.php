<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Problem;

use App\Shared\Domain\Exception\CodedDomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every error code a problem response can carry (ADR 0028 §5): the codes of the domain exceptions
 * mapped in `api_platform.exception_to_status`. The OpenAPI document lists them, so the clients'
 * generated types know them; `ErrorCodesTest` checks the list against what the exceptions produce.
 */
final readonly class ErrorCodes
{
    /** @param array<string, int> $exceptionToStatus */
    public function __construct(
        #[Autowire(param: 'api_platform.exception_to_status')]
        private array $exceptionToStatus,
    ) {
    }

    /** @return list<string> sorted */
    public function all(): array
    {
        $codes = [];
        foreach (array_keys($this->exceptionToStatus) as $class) {
            if (is_subclass_of($class, CodedDomainException::class)) {
                array_push($codes, ...self::of($class));
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * The codes an exception class produces: `<module>.<class>`, or one `<module>.<class>.<variant>`
     * per named constructor when it has several (CodedDomainException).
     *
     * @param class-string<CodedDomainException> $class
     *
     * @return list<string>
     */
    public static function of(string $class): array
    {
        $factories = array_values(array_filter(
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                && \in_array((string) $method->getReturnType(), ['self', 'static', $class], true),
        ));
        if (\count($factories) <= 1) {
            return [$class::classErrorCode()];
        }

        return array_map(
            static fn (\ReflectionMethod $method): string => $class::classErrorCode().'.'.CodedDomainException::snakeCase($method->getName()),
            $factories,
        );
    }
}
