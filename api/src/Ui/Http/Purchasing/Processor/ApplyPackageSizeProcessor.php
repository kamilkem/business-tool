<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Purchasing\ApplyPackageSizeGroupInput;
use Ui\Http\Purchasing\ApplyPackageSizeInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager sets a package size on the items of chosen groups of a product of the organization selected
 * by the X-Organization-Id header (ADR 0049); the answer has no body.
 *
 * @implements ProcessorInterface<ApplyPackageSizeInput, null>
 */
final readonly class ApplyPackageSizeProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param ApplyPackageSizeInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        $this->commandBus->handle(new ApplyPackageSizeCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $productId,
            $data->packageSize,
            $data->bundleCount,
            array_map(static fn (ApplyPackageSizeGroupInput $group): array => ['unitKey' => $group->unitKey, 'packageSize' => $group->packageSize, 'bundleCount' => $group->bundleCount], $data->groups),
        ));

        return null;
    }
}
