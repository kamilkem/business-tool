<?php

declare(strict_types=1);

namespace Ui\Http\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Query\GetMe\GetMeQuery;
use App\User\Application\Query\GetMe\MeView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\User\MeResource;

/** @implements ProviderInterface<MeResource> */
final readonly class GetMeProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MeResource
    {
        /** @var MeView $view */
        $view = $this->queryBus->ask(new GetMeQuery($this->currentUser->id()));

        return MeResource::fromView($view);
    }
}
