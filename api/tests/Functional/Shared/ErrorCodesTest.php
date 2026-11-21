<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Domain\Exception\CodedDomainException;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Ui\Http\Shared\Problem\ErrorCodes;

/**
 * Error codes are a contract with the clients, which translate them (ADR 0028 §5): every domain
 * exception has one and is mapped to a status, every named constructor produces the code the OpenAPI
 * document lists, with camelCase parameter names, and the list changes only on purpose (error-codes.txt).
 */
final class ErrorCodesTest extends KernelTestCase
{
    private const string SNAPSHOT = __DIR__.'/error-codes.txt';
    private const string CODE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/';

    public function test_it_gives_every_mapped_exception_of_ours_a_code(): void
    {
        $withoutCode = array_values(array_filter(
            array_keys(self::exceptionToStatus()),
            static fn (string $class): bool => str_starts_with($class, 'App\\') && !is_subclass_of($class, CodedDomainException::class),
        ));

        self::assertSame([], $withoutCode, 'These exceptions are mapped in exception_to_status but have no code: extend the module\'s base exception.');
    }

    public function test_it_maps_every_domain_exception_to_a_status(): void
    {
        $unmapped = array_values(array_diff(self::domainExceptions(), array_keys(self::exceptionToStatus())));

        self::assertSame([], $unmapped, 'Map these in config/packages/api_platform.yaml exception_to_status: an unmapped one is a 500.');
    }

    public function test_it_produces_the_listed_code_and_primitive_parameters_from_every_named_constructor(): void
    {
        foreach (self::domainExceptions() as $class) {
            $listed = ErrorCodes::of($class);
            foreach (self::instancesOf($class) as $factory => $exception) {
                self::assertContains($exception->errorCode(), $listed, $class.'::'.$factory.'(): a class with several named constructors passes variant: __FUNCTION__ in each of them, one with a single one passes none.');
                self::assertMatchesRegularExpression(self::CODE_PATTERN, $exception->errorCode());
                // Their values are primitives by CodedDomainException's type, which PHPStan checks.
                foreach (array_keys($exception->errorParameters()) as $name) {
                    self::assertMatchesRegularExpression('/^[a-z][a-zA-Z0-9]*$/', $name, $class.'::'.$factory.'(): parameter names are camelCase.');
                }
            }
        }
    }

    public function test_it_keeps_the_codes_unique_and_as_listed(): void
    {
        $codes = static::getContainer()->get(ErrorCodes::class)->all();
        $produced = array_merge(...array_map(ErrorCodes::of(...), self::domainExceptions()));
        sort($produced);

        self::assertSame($produced, array_values(array_unique($produced)), 'Two exceptions produce the same code.');
        self::assertSame($produced, $codes);
        self::assertSame(
            array_values(array_filter(explode("\n", (string) file_get_contents(self::SNAPSHOT)))),
            $codes,
            'The error codes changed. Clients translate them (apiError.<code>): if this is on purpose, update '.basename(self::SNAPSHOT).' and the clients\' catalogs, and run make types.',
        );
    }

    /** @return array<string, int> */
    private static function exceptionToStatus(): array
    {
        /** @var array<string, int> $map */
        $map = static::getContainer()->getParameter('api_platform.exception_to_status');

        return $map;
    }

    /** @return list<class-string<CodedDomainException>> the concrete domain exceptions in src/App */
    private static function domainExceptions(): array
    {
        $classes = [];
        $finder = (new Finder())->files()->in(\dirname(__DIR__, 3).'/src/App')->path('#Domain/(.+/)?Exception/#')->name('*Exception.php');
        foreach ($finder as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (is_subclass_of($class, CodedDomainException::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * One instance per named constructor (or from the constructor, for a class without any), built
     * with placeholder arguments.
     *
     * @param class-string<CodedDomainException> $class
     *
     * @return array<string, CodedDomainException>
     */
    private static function instancesOf(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $instances = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $class && 'self' === (string) $method->getReturnType()) {
                $instance = $method->invokeArgs(null, array_map(self::placeholder(...), $method->getParameters()));
                \assert($instance instanceof CodedDomainException);
                $instances[$method->getName()] = $instance;
            }
        }
        if ([] === $instances) {
            $constructor = $reflection->getConstructor();
            self::assertNotNull($constructor);
            self::assertTrue($constructor->isPublic(), $class.' has neither a named constructor nor a public constructor.');
            $instances['__construct'] = $reflection->newInstanceArgs(array_map(self::placeholder(...), $constructor->getParameters()));
        }

        return $instances;
    }

    private static function placeholder(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type, $parameter->getDeclaringClass()?->getName().': a placeholder for a union type is not implemented.');

        return match ($type->getName()) {
            'string' => 'x',
            'int' => 2,
            'bool' => true,
            'array' => [],
            BusinessDate::class => BusinessDate::of(2026, 11, 2),
            BusinessPeriod::class => BusinessPeriod::month(2026, 11),
            \DateTimeImmutable::class => new \DateTimeImmutable('2026-11-02T10:00:00+00:00'),
            'self' => self::simplestInstanceOf(self::classOf($parameter)),
            default => throw new \LogicException(\sprintf('No placeholder for %s $%s.', $type->getName(), $parameter->getName())),
        };
    }

    /**
     * An instance from a named constructor that takes no instance of its own class (the previous
     * exception that `SecretUnreadableException::ofSecret()` wraps).
     *
     * @param class-string<CodedDomainException> $class
     */
    private static function simplestInstanceOf(string $class): CodedDomainException
    {
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            $parameterTypes = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $method->getParameters());
            if ($method->getDeclaringClass()->getName() === $class && 'self' === (string) $method->getReturnType() && !\in_array('self', $parameterTypes, true)) {
                $instance = $method->invokeArgs(null, array_map(self::placeholder(...), $method->getParameters()));
                \assert($instance instanceof CodedDomainException);

                return $instance;
            }
        }

        throw new \LogicException($class.' has no named constructor without an instance of itself.');
    }

    /** @return class-string<CodedDomainException> */
    private static function classOf(\ReflectionParameter $parameter): string
    {
        $class = $parameter->getDeclaringClass()?->getName();
        \assert(null !== $class && is_subclass_of($class, CodedDomainException::class));

        return $class;
    }
}
