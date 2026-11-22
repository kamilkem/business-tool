<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseForm;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\DayCloseOffers;
use App\CashFlow\Application\DayCloseRules;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Organization\Application\Query\GetMemberName\GetMemberNameQuery;
use App\Organization\Application\Query\GetMemberName\MemberNameView;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use Psr\Clock\ClockInterface;

final readonly class GetDayCloseFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayCloseOffers $offers,
        private DayCloseRules $rules,
        private DayCloseRepositoryInterface $closes,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetDayCloseFormQuery $query): ?DayCloseFormView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        $now = $this->clock->now();
        $timeZone = new \DateTimeZone($actor->organization->timezone);
        $rule = $this->rules->current();
        $proposed = $rule->proposedDate($now, $timeZone);
        $offer = $this->offers->current($actor);

        $preselected = null;
        if (1 === \count($offer->registers)) {
            $preselected = (string) array_key_first($offer->registers);
        } else {
            $latest = $this->closes->findLatest()?->getRegisterId()->toRfc4122();
            $preselected = null !== $latest && isset($offer->registers[$latest]) ? $latest : null;
        }

        return new DayCloseFormView(
            $offer->currency,
            $offer->canClose(),
            $proposed->toString(),
            BusinessDate::containing($now, $timeZone)->toString(),
            $rule->mode->value,
            $rule->dayEndsAt->toString(),
            array_map(static fn (CashStorage $storage): DayCloseFormOptionView => new DayCloseFormOptionView($storage->getId()->toRfc4122(), $storage->getName(), $storage->getMode()->value, null), $offer->storages),
            array_map(static fn (Denomination $denomination): DayCloseFormOptionView => new DayCloseFormOptionView($denomination->getId()->toRfc4122(), null, $denomination->getKind()->value, $denomination->getValue()), $offer->denominations),
            self::options($offer->registers),
            $preselected,
            self::options($offer->nonCashMethods),
            $this->closed($proposed, $query->organizationId, $query->actorId),
        );
    }

    /**
     * @param array<string, string> $names by id
     *
     * @return list<DayCloseFormOptionView>
     */
    private static function options(array $names): array
    {
        $options = [];
        foreach ($names as $id => $name) {
            $options[] = new DayCloseFormOptionView((string) $id, $name, null, null);
        }

        return $options;
    }

    private function closed(BusinessDate $date, string $organizationId, string $actorId): ?DayCloseFormClosedView
    {
        $close = $this->closes->findByDate($date);
        if (null === $close) {
            return null;
        }

        /** @var MemberNameView|null $name */
        $name = $this->queryBus->ask(new GetMemberNameQuery($organizationId, $actorId, $close->getClosedBy()->toRfc4122()));

        return new DayCloseFormClosedView(
            $close->getId()->toRfc4122(),
            $close->getClosedBy()->toRfc4122(),
            $name->firstName ?? '',
            $name->lastName ?? '',
            $close->getClosedAt(),
        );
    }
}
