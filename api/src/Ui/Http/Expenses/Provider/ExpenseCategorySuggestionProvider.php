<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Expenses\Application\Query\SuggestExpenseCategory\ExpenseCategorySuggestionView;
use App\Expenses\Application\Query\SuggestExpenseCategory\SuggestExpenseCategoryQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Expenses\ExpenseCategorySuggestionResource;
use Ui\Http\Expenses\SuggestExpenseCategoryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The category to suggest for ?sellerTaxId=&sellerName= in the organization selected by the
 * X-Organization-Id header, after validating them as a SuggestExpenseCategoryInput (422).
 *
 * @implements ProviderInterface<ExpenseCategorySuggestionResource>
 */
final readonly class ExpenseCategorySuggestionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseCategorySuggestionResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new SuggestExpenseCategoryInput(
            sellerTaxId: self::stringParameter($operation, 'sellerTaxId'),
            sellerName: self::stringParameter($operation, 'sellerName'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var ExpenseCategorySuggestionView|null $view */
        $view = $this->queryBus->ask(new SuggestExpenseCategoryQuery($organizationId, $this->currentUser->id(), $input->sellerTaxId, $input->sellerName));

        return ExpenseCategorySuggestionResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /** The parameter's value, or null when it is missing or not a single string. */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
