<?php

declare(strict_types=1);

namespace Ui\Http\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\ChangeLanguage\ChangeLanguageCommand;
use App\User\Application\Query\GetMe\GetMeQuery;
use App\User\Application\Query\GetMe\MeView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\User\MeResource;
use Ui\Http\User\UpdateMeInput;

/** @implements ProcessorInterface<UpdateMeInput, MeResource> */
final readonly class UpdateMeProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    /** @param UpdateMeInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MeResource
    {
        $userId = $this->currentUser->id();

        $this->commandBus->handle(new ChangeLanguageCommand($userId, $data->language?->value));

        /** @var MeView $view */
        $view = $this->queryBus->ask(new GetMeQuery($userId));

        return MeResource::fromView($view);
    }
}
