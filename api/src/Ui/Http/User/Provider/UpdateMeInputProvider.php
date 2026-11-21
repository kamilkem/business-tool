<?php

declare(strict_types=1);

namespace Ui\Http\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Query\GetMe\GetMeQuery;
use App\User\Application\Query\GetMe\MeView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\User\UpdateMeInput;

/**
 * Pre-fills the merge-patch input with the signed-in user's current settings; API Platform then
 * applies the request body onto it.
 *
 * @implements ProviderInterface<UpdateMeInput>
 */
final readonly class UpdateMeInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateMeInput
    {
        /** @var MeView $view */
        $view = $this->queryBus->ask(new GetMeQuery($this->currentUser->id()));

        return UpdateMeInput::fromView($view);
    }
}
