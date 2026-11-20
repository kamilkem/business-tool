<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetPayrollRun\GetPayrollRunQuery;
use App\Payroll\Application\Query\GetPayrollRun\PayrollRunView;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Payroll\UpdatePaycheckAdjustmentInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the adjustment's current values; API Platform then applies the
 * request body onto it. A run or adjustment the signed-in member cannot see is a 404 here; an employee
 * sees no draft, and the command refuses them anyway (403).
 *
 * @implements ProviderInterface<UpdatePaycheckAdjustmentInput>
 */
final readonly class UpdatePaycheckAdjustmentInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdatePaycheckAdjustmentInput
    {
        $runId = $uriVariables['id'];
        $adjustmentId = $uriVariables['adjustmentId'];
        \assert(\is_string($runId) && \is_string($adjustmentId));

        /** @var PayrollRunView|null $run */
        $run = $this->queryBus->ask(new GetPayrollRunQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $runId,
        ));
        if (null === $run) {
            throw PayrollRunNotFoundException::forId($runId);
        }
        foreach ($run->paychecks as $paycheck) {
            foreach ($paycheck->adjustments as $adjustment) {
                if ($adjustment->id === $adjustmentId) {
                    return UpdatePaycheckAdjustmentInput::fromView($adjustment);
                }
            }
        }

        throw PaycheckAdjustmentNotFoundException::forId($adjustmentId);
    }
}
