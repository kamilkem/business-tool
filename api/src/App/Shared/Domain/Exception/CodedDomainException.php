<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Base of every module's base exception (ADR 0028 §5). Besides its English message, a domain
 * exception carries a stable, machine-readable code and primitive parameters, which problem
 * responses return as `code` and `parameters` for the clients to translate.
 *
 * The code is derived from names, never written by hand:
 * `<module>.<class>[.<variant>]`, where
 * - `<module>` is what the module's base exception returns from `module()` (`income`),
 * - `<class>` is the class's short name without `Exception`, without a leading module name, in
 *   snake_case (`RegisterNameTakenException` → `register_name_taken`,
 *   `KsefTokenRejectedException` → `token_rejected`),
 * - `<variant>` is the named constructor in snake_case, given only by classes with several named
 *   constructors, and then by all of them (`PayrollRunNotApprovableException::openShifts()` →
 *   `payroll.run_not_approvable.open_shifts`).
 *
 * Renaming a class or a named constructor therefore changes a code the clients translate:
 * `ErrorCodesTest` lists every code and fails until the list, and the clients' catalogs, follow.
 */
abstract class CodedDomainException extends \DomainException
{
    /** @var array<string, string|int|bool|null> */
    private array $errorParameters;

    /**
     * @param array<string, string|int|bool|null> $parameters primitives only: ids, names, counts,
     *                                                         dates as Y-m-d, instants as ISO 8601
     */
    public function __construct(
        string $message,
        array $parameters = [],
        private readonly ?string $variant = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorParameters = $parameters;
    }

    /** The module part of the code, in snake_case (`income`, `time_tracking`). */
    abstract public static function module(): string;

    /** The code without a variant: `<module>.<class>`. */
    final public static function classErrorCode(): string
    {
        $name = substr(strrchr('\\'.static::class, '\\') ?: static::class, 1);
        $name = (string) preg_replace('/Exception$/', '', $name);
        $module = str_replace(' ', '', ucwords(str_replace('_', ' ', static::module())));
        if (str_starts_with($name, $module) && 1 === preg_match('/^[A-Z]/', substr($name, \strlen($module)))) {
            $name = substr($name, \strlen($module));
        }

        return static::module().'.'.self::snakeCase($name);
    }

    final public function errorCode(): string
    {
        return null === $this->variant ? static::classErrorCode() : static::classErrorCode().'.'.self::snakeCase($this->variant);
    }

    /** @return array<string, string|int|bool|null> */
    final public function errorParameters(): array
    {
        return $this->errorParameters;
    }

    final public static function snakeCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
    }
}
