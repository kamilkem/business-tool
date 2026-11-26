<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, PATCH, DELETE /products and POST /products/{id}/merge (#241). Organizations arranged by
 * the factories start without categories or products; the server's time is mocked at NOW (UTC).
 */
final class ProductApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_create_a_product(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $whisky = self::addCategory($organization, 'Whisky');
        self::mockTime(self::NOW);

        $response = self::create($actor, $organization, [
            'name' => '  Jack Daniel\'s  ',
            'measure' => 'volume',
            'categoryId' => $whisky->getId()->toRfc4122(),
            'referenceQuantity' => '0.50',
            'excluded' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        $product = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($product['id']));
        self::assertSame([
            'id' => $product['id'],
            'name' => 'Jack Daniel\'s',
            'categoryId' => $whisky->getId()->toRfc4122(),
            'measure' => 'volume',
            'referenceQuantity' => '0.5',
            'excluded' => true,
            'createdAt' => '2026-11-13T10:00:00+00:00',
        ], $product);
        self::assertSame($product, self::get($actor, $organization, $product['id'])->toArray());
        self::assertSame([$product], self::list($actor, $organization)->toArray());
    }

    public function test_it_defaults_the_reference_quantity_to_1_and_leaves_none_for_a_product_without_a_unit(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $beer = self::create($owner, $organization, ['name' => 'Beer', 'measure' => 'volume'])->toArray();
        $coffee = self::create($owner, $organization, ['name' => 'Coffee', 'measure' => 'mass', 'referenceQuantity' => null])->toArray();
        $lemons = self::create($owner, $organization, ['name' => 'Lemons', 'measure' => 'count'])->toArray();
        $rent = self::create($owner, $organization, ['name' => 'Rent', 'measure' => 'none'])->toArray();
        $deposit = self::create($owner, $organization, ['name' => 'Deposit', 'measure' => 'none', 'referenceQuantity' => null])->toArray();
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['1', '1', '1', null, null], [$beer['referenceQuantity'], $coffee['referenceQuantity'], $lemons['referenceQuantity'], $rent['referenceQuantity'], $deposit['referenceQuantity']]);
        self::assertSame([null, false], [$beer['categoryId'], $beer['excluded']]);
    }

    public function test_it_answers_409_for_a_name_another_product_has_in_any_case(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addProduct($organization, 'Cola');
        self::addProduct($elsewhere, 'Fanta');

        foreach (['Cola', 'COLA', ' cola '] as $name) {
            $response = self::create($owner, $organization, ['name' => $name, 'measure' => 'volume']);
            self::assertResponseStatusCodeSame(409, $name);
        }
        self::assertSame('purchasing.product_name_taken', $response->toArray(false)['code']);
        // Names are unique per organization: another organization's product does not count.
        self::create($owner, $organization, ['name' => 'Fanta', 'measure' => 'volume']);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, self::list($owner, $organization)->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNewProducts(): iterable
    {
        yield 'no name' => [['measure' => 'volume']];
        yield 'null name' => [['name' => null, 'measure' => 'volume']];
        yield 'empty name' => [['name' => '', 'measure' => 'volume']];
        yield 'blank name' => [['name' => '   ', 'measure' => 'volume']];
        yield 'long name' => [['name' => 'x', 'measure' => 'volume', 'nameOverride' => true]];
        yield 'name of the wrong type' => [['name' => 5, 'measure' => 'volume']];
        yield 'no measure' => [['name' => 'Beer']];
        yield 'null measure' => [['name' => 'Beer', 'measure' => null]];
        yield 'unknown measure' => [['name' => 'Beer', 'measure' => 'bottles']];
        yield 'category that is not a uuid' => [['name' => 'Beer', 'measure' => 'volume', 'categoryId' => 'not-a-uuid']];
        yield 'zero reference quantity' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => '0']];
        yield 'negative reference quantity' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => '-0.5']];
        yield 'reference quantity that is not a number' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => 'half']];
        yield 'reference quantity with a comma' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => '0,5']];
        yield 'reference quantity with too many decimals' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => '0.0000001']];
        yield 'reference quantity that is a number' => [['name' => 'Beer', 'measure' => 'volume', 'referenceQuantity' => 0.5]];
        yield 'reference quantity for a product without a unit' => [['name' => 'Rent', 'measure' => 'none', 'referenceQuantity' => '1']];
        yield 'excluded of the wrong type' => [['name' => 'Beer', 'measure' => 'volume', 'excluded' => 'yes']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNewProducts')]
    public function test_it_answers_422_for_an_invalid_new_product(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        if (isset($body['nameOverride'])) {
            unset($body['nameOverride']);
            $body['name'] = str_repeat('x', Product::NAME_MAX_LENGTH + 1);
        }

        $response = self::create($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::list($owner, $organization)->toArray());
        self::assertNotNull($response->toArray(false)['detail'] ?? null);
    }

    public function test_it_codes_the_reference_quantity_that_does_not_fit_the_measure(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $notAllowed = self::create($owner, $organization, ['name' => 'Rent', 'measure' => 'none', 'referenceQuantity' => '1'])->toArray(false);
        self::assertSame('purchasing.invalid_product_reference_quantity.not_allowed_for_measure', $notAllowed['code']);
        self::assertSame(['referenceQuantity' => '1', 'measure' => 'none'], $notAllowed['parameters']);
    }

    public function test_it_accepts_a_name_of_128_characters_after_trimming(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::create($owner, $organization, ['name' => ' '.str_repeat('é', Product::NAME_MAX_LENGTH).' ', 'measure' => 'count']);

        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_422_for_a_category_that_does_not_exist_or_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Whisky');

        foreach ([Uuid::v7()->toRfc4122(), $foreign->getId()->toRfc4122()] as $categoryId) {
            $response = self::create($owner, $organization, ['name' => 'Beer', 'measure' => 'volume', 'categoryId' => $categoryId]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('purchasing.unknown_product_category', $response->toArray(false)['code']);
        }
        self::assertSame([], self::list($owner, $organization)->toArray());
    }

    public function test_it_lists_the_products_by_name_with_their_fields(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addProduct($organization, 'rent', measure: ProductMeasure::None);
        self::addProduct($organization, 'Cola');
        self::addProduct($organization, 'Beer', excluded: true);

        $products = self::list($owner, $organization)->toArray();

        self::assertSame(['Beer', 'Cola', 'rent'], array_column($products, 'name'));
        self::assertSame(['id', 'name', 'categoryId', 'measure', 'referenceQuantity', 'excluded', 'createdAt'], array_keys($products[0]));
        self::assertSame([true, false, false], array_column($products, 'excluded'));
        self::assertSame(['1', '1', null], array_column($products, 'referenceQuantity'));
    }

    public function test_it_filters_the_list_by_a_category_and_its_descendants_and_by_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);
        $bourbon = self::addCategory($organization, 'Bourbon', $whisky);
        $food = self::addCategory($organization, 'Food');
        self::addProduct($organization, 'Beer', $alcohol);
        self::addProduct($organization, 'Jack Daniel\'s', $bourbon);
        self::addProduct($organization, 'Johnnie Walker', $whisky);
        self::addProduct($organization, 'Napkins', $food);
        self::addProduct($organization, 'Cola');
        self::addProduct($organization, '100% juice');

        $names = static fn (array $query): array => array_column(self::list($owner, $organization, $query)->toArray(), 'name');

        self::assertSame(['Beer', 'Jack Daniel\'s', 'Johnnie Walker'], $names(['categoryId' => $alcohol->getId()->toRfc4122()]));
        self::assertSame(['Jack Daniel\'s', 'Johnnie Walker'], $names(['categoryId' => $whisky->getId()->toRfc4122()]));
        self::assertSame(['Jack Daniel\'s'], $names(['categoryId' => $bourbon->getId()->toRfc4122()]));
        self::assertSame(['Napkins'], $names(['categoryId' => $food->getId()->toRfc4122()]));
        self::assertSame(['Cola'], $names(['search' => 'COL']));
        self::assertSame(['100% juice', 'Jack Daniel\'s', 'Johnnie Walker'], $names(['search' => 'j']));
        self::assertSame(['Jack Daniel\'s'], $names(['search' => 'daniel', 'categoryId' => $alcohol->getId()->toRfc4122()]));
        self::assertSame([], $names(['search' => 'daniel', 'categoryId' => $food->getId()->toRfc4122()]));
        // The wildcards of LIKE are plain characters.
        self::assertSame(['100% juice'], $names(['search' => '%']));
        self::assertSame([], $names(['search' => '_']));
        self::assertCount(6, $names(['search' => '']));
        self::assertSame([], $names(['categoryId' => Uuid::v7()->toRfc4122()]));
    }

    public function test_it_answers_422_for_a_category_filter_that_is_not_a_uuid(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::list($owner, $organization, ['categoryId' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(422);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_change_a_product(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $whisky = self::addCategory($organization, 'Whisky');
        $food = self::addCategory($organization, 'Food');
        $id = self::addProduct($organization, 'Jack', $whisky, referenceQuantity: '0.7')->getId()->toRfc4122();

        $renamed = self::update($actor, $organization, $id, ['name' => ' Jack Daniel\'s '])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Jack Daniel\'s', $whisky->getId()->toRfc4122(), 'volume', '0.7', false], [$renamed['name'], $renamed['categoryId'], $renamed['measure'], $renamed['referenceQuantity'], $renamed['excluded']]);

        $moved = self::update($actor, $organization, $id, ['categoryId' => $food->getId()->toRfc4122(), 'referenceQuantity' => '0.5'])->toArray();
        self::assertSame([$food->getId()->toRfc4122(), '0.5'], [$moved['categoryId'], $moved['referenceQuantity']]);

        $excluded = self::update($actor, $organization, $id, ['excluded' => true])->toArray();
        self::assertTrue($excluded['excluded']);

        $counted = self::update($actor, $organization, $id, ['measure' => 'count', 'referenceQuantity' => '6', 'excluded' => false, 'categoryId' => null])->toArray();
        self::assertSame(['count', '6', false, null], [$counted['measure'], $counted['referenceQuantity'], $counted['excluded'], $counted['categoryId']]);

        self::assertSame($counted, self::get($actor, $organization, $id)->toArray());
        self::assertSame($renamed['id'], $counted['id']);
        self::assertSame($renamed['createdAt'], $counted['createdAt']);
    }

    public function test_it_keeps_what_the_body_does_not_send_and_accepts_the_same_values(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $whisky = self::addCategory($organization, 'Whisky');
        $id = self::addProduct($organization, 'Jack', $whisky, referenceQuantity: '0.7')->getId()->toRfc4122();
        $before = self::get($owner, $organization, $id)->toArray();

        self::update($owner, $organization, $id, []);
        self::assertResponseStatusCodeSame(200);
        self::update($owner, $organization, $id, $before);
        self::assertResponseStatusCodeSame(200);
        self::update($owner, $organization, $id, ['name' => 'JACK']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['JACK'], [self::get($owner, $organization, $id)->toArray()['name']]);
        self::assertSame(array_diff_key($before, ['name' => 0]), array_diff_key(self::get($owner, $organization, $id)->toArray(), ['name' => 0]));
    }

    public function test_it_changes_the_measure_to_none_with_a_null_reference_quantity_and_back_to_the_default(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addProduct($organization, 'Electricity', referenceQuantity: '0.7')->getId()->toRfc4122();

        // The reference quantity it had is still there in the body that was not sent: 422, as for a new product.
        $response = self::update($owner, $organization, $id, ['measure' => 'none']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('purchasing.invalid_product_reference_quantity.not_allowed_for_measure', $response->toArray(false)['code']);

        $none = self::update($owner, $organization, $id, ['measure' => 'none', 'referenceQuantity' => null])->toArray();
        self::assertSame(['none', null], [$none['measure'], $none['referenceQuantity']]);

        $back = self::update($owner, $organization, $id, ['measure' => 'mass'])->toArray();
        self::assertSame(['mass', '1'], [$back['measure'], $back['referenceQuantity']]);
    }

    public function test_it_queues_matching_when_a_product_is_renamed_or_gets_another_measure_and_not_for_other_changes(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $whisky = self::addCategory($organization, 'Whisky');
        $id = self::addProduct($organization, 'Jack', $whisky, referenceQuantity: '0.7')->getId()->toRfc4122();
        $org = $organization->getId()->toRfc4122();

        self::update($owner, $organization, $id, ['categoryId' => null, 'referenceQuantity' => '1', 'excluded' => true]);
        $none = self::queuedJobs();
        self::assertCount(0, $none, 'The category, the reference quantity and the exclusion are not what the model sees.');

        self::update($owner, $organization, $id, ['name' => 'Jack Daniel\'s']);
        $queued = self::queuedJobs();
        self::assertSame(
            ['purchasing-match-items:'.$org.':not_yet_matched:model', 'purchasing-match-items:'.$org.':after-catalog-change'],
            array_map(static fn (Envelope $envelope): string => (string) $envelope->last(DeduplicateStamp::class)?->getKey(), $queued),
        );
        self::assertNull($queued[0]->last(DelayStamp::class));
        self::assertSame(MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS * 1000, $queued[1]->last(DelayStamp::class)?->getDelay(), 'The pass over the unassigned items waits for the burst to settle.');
        $job = $queued[1]->getMessage();
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame(['unassigned', true], [$job->scope, $job->useInterpreter]);
    }

    public function test_it_queues_the_delayed_pass_over_the_unassigned_items_when_a_product_is_created(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::create($owner, $organization, ['name' => 'Tyskie', 'measure' => 'volume']);

        self::assertResponseStatusCodeSame(201);
        $queued = self::queuedJobs();
        $org = $organization->getId()->toRfc4122();
        self::assertSame(
            ['purchasing-match-items:'.$org.':not_yet_matched:model', 'purchasing-match-items:'.$org.':after-catalog-change'],
            array_map(static fn (Envelope $envelope): string => (string) $envelope->last(DeduplicateStamp::class)?->getKey(), $queued),
        );
        self::assertSame(MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS * 1000, $queued[1]->last(DelayStamp::class)?->getDelay());
    }

    public function test_it_answers_409_for_a_name_another_product_has_on_update(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addProduct($organization, 'Fanta');
        $id = self::addProduct($organization, 'Cola')->getId()->toRfc4122();

        $response = self::update($owner, $organization, $id, ['name' => 'FANTA']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('purchasing.product_name_taken', $response->toArray(false)['code']);
        self::assertSame('Cola', self::get($owner, $organization, $id)->toArray()['name']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUpdates(): iterable
    {
        yield 'null name' => [['name' => null]];
        yield 'blank name' => [['name' => '   ']];
        yield 'long name' => [['name' => str_repeat('x', Product::NAME_MAX_LENGTH + 1)]];
        yield 'null measure' => [['measure' => null]];
        yield 'unknown measure' => [['measure' => 'bottles']];
        yield 'null excluded' => [['excluded' => null]];
        yield 'category that is not a uuid' => [['categoryId' => 'not-a-uuid']];
        yield 'zero reference quantity' => [['referenceQuantity' => '0']];
        yield 'negative reference quantity' => [['referenceQuantity' => '-1']];
        yield 'reference quantity that is not a number' => [['referenceQuantity' => 'half']];
        yield 'category that does not exist' => [['categoryId' => '018f0000-0000-7000-8000-000000000000']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidUpdates')]
    public function test_it_answers_422_for_an_invalid_update(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addProduct($organization, 'Cola', referenceQuantity: '0.5')->getId()->toRfc4122();
        $before = self::get($owner, $organization, $id)->toArray();

        self::update($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, self::get($owner, $organization, $id)->toArray());
    }

    public function test_it_answers_422_for_a_new_category_that_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addProduct($organization, 'Cola')->getId()->toRfc4122();

        self::update($owner, $organization, $id, ['categoryId' => self::addCategory($elsewhere, 'Whisky')->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::get($owner, $organization, $id)->toArray()['categoryId']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_delete_a_product_without_items(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addProduct($organization, 'Cola')->getId()->toRfc4122();

        $response = self::delete($actor, $organization, $id);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame([], self::list($actor, $organization)->toArray());
        self::get($actor, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        // The name is free again.
        self::create($actor, $organization, ['name' => 'Cola', 'measure' => 'volume']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_409_for_deleting_a_product_with_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $product = self::addProduct($organization, 'Cola');
        self::linkItems($organization, $product, 2);

        $response = self::delete($owner, $organization, $product->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('purchasing.product_in_use', $response->toArray(false)['code']);
        self::assertCount(1, self::list($owner, $organization)->toArray());
    }

    #[DataProvider('managingRoles')]
    public function test_it_merges_a_product_into_another_and_moves_its_items_keeping_their_link(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $source = self::addProduct($organization, 'J. Daniels 1l');
        $target = self::addProduct($organization, 'Jack Daniel\'s', referenceQuantity: '0.7');
        $bystander = self::addProduct($organization, 'Cola');
        $sourceItems = self::linkItems($organization, $source, 2, status: 'confirmed', packageSize: '1');
        $targetItems = self::linkItems($organization, $target, 1, status: 'assigned', packageSize: '0.7');
        $bystanderItems = self::linkItems($organization, $bystander, 1, status: 'suggested', packageSize: '0.33');

        $response = self::merge($actor, $organization, $source->getId()->toRfc4122(), ['into' => $target->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => $target->getId()->toRfc4122(), 'name' => 'Jack Daniel\'s', 'referenceQuantity' => '0.7'], array_intersect_key($response->toArray(), array_flip(['id', 'name', 'referenceQuantity'])));
        self::assertSame(['Cola', 'Jack Daniel\'s'], array_column(self::list($actor, $organization)->toArray(), 'name'));
        self::get($actor, $organization, $source->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        // The items moved with their status and package size; nothing else changed.
        $linked = self::linkedItems();
        foreach ($sourceItems as $item) {
            self::assertSame([$target->getId()->toRfc4122(), 'confirmed', '1.000000'], $linked[$item]);
        }
        self::assertSame([$target->getId()->toRfc4122(), 'assigned', '0.700000'], $linked[$targetItems[0]]);
        self::assertSame([$bystander->getId()->toRfc4122(), 'suggested', '0.330000'], $linked[$bystanderItems[0]]);
    }

    public function test_it_merges_a_product_with_no_items_and_keeps_the_settings_of_the_target(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $whisky = self::addCategory($organization, 'Whisky');
        $source = self::addProduct($organization, 'Rent', measure: ProductMeasure::None, excluded: true);
        $target = self::addProduct($organization, 'Jack Daniel\'s', $whisky, referenceQuantity: '0.7');

        $merged = self::merge($owner, $organization, $source->getId()->toRfc4122(), ['into' => $target->getId()->toRfc4122()])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$whisky->getId()->toRfc4122(), 'volume', '0.7', false], [$merged['categoryId'], $merged['measure'], $merged['referenceQuantity'], $merged['excluded']]);
    }

    public function test_it_answers_404_for_merging_into_a_product_that_does_not_exist_or_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $source = self::addProduct($organization, 'Cola');
        $foreign = self::addProduct($elsewhere, 'Cola Zero');
        self::linkItems($organization, $source, 2);

        foreach ([Uuid::v7()->toRfc4122(), $foreign->getId()->toRfc4122()] as $into) {
            $response = self::merge($owner, $organization, $source->getId()->toRfc4122(), ['into' => $into]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('purchasing.product_merge_target_not_found', $response->toArray(false)['code']);
        }

        self::assertCount(1, self::list($owner, $organization)->toArray());
        self::assertSame(2, self::countLinked($source));
        self::assertSame(0, self::countLinked($foreign));
    }

    public function test_it_answers_422_for_merging_into_itself_or_without_a_target(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $source = self::addProduct($organization, 'Cola');
        self::linkItems($organization, $source, 1);
        $id = $source->getId()->toRfc4122();

        $response = self::merge($owner, $organization, $id, ['into' => $id]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('purchasing.product_merge_into_itself', $response->toArray(false)['code']);
        foreach ([[], ['into' => null], ['into' => ''], ['into' => 'not-a-uuid'], ['into' => 5]] as $body) {
            self::merge($owner, $organization, $id, $body);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertCount(1, self::list($owner, $organization)->toArray());
        self::assertSame(1, self::countLinked($source));
    }

    public function test_it_answers_404_for_an_unknown_product(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = self::addProduct($organization, 'Cola')->getId()->toRfc4122();
        $unknown = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::update($owner, $organization, $unknown, ['name' => 'Rent']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::merge($owner, $organization, $unknown, ['into' => $other]);
        self::assertResponseStatusCodeSame(404);
        foreach (['get', 'delete'] as $method) {
            self::$method($owner, $organization, 'not-a-uuid');
            self::assertResponseStatusCodeSame(404);
        }
        self::update($owner, $organization, 'not-a-uuid', ['name' => 'Rent']);
        self::assertResponseStatusCodeSame(404);
        self::merge($owner, $organization, 'not-a-uuid', ['into' => $other]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_products(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addProduct($organization, 'Cola');
        $foreign = self::addProduct($elsewhere, 'Fanta');
        $id = $foreign->getId()->toRfc4122();
        self::linkItems($elsewhere, $foreign, 1);
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::list($ownerElsewhere, $elsewhere)->toArray();

        self::assertSame(['Cola'], array_column(self::list($owner, $organization)->toArray(), 'name'));
        self::assertSame([], self::list($owner, $organization, ['search' => 'fanta'])->toArray());
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::update($owner, $organization, $id, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::merge($owner, $organization, $id, ['into' => self::addProduct($organization, 'Beer')->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::list($ownerElsewhere, $elsewhere)->toArray());
        self::assertSame(1, self::countLinked($foreign));
    }

    public function test_it_refuses_to_delete_a_category_that_has_products_and_lets_it_go_once_they_move(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $whisky = self::addCategory($organization, 'Whisky');
        $id = self::addProduct($organization, 'Jack Daniel\'s', $whisky)->getId()->toRfc4122();

        $response = self::deleteCategory($owner, $organization, $whisky);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('purchasing.product_category_in_use.has_products', $response->toArray(false)['code']);

        self::update($owner, $organization, $id, ['categoryId' => null]);
        self::deleteCategory($owner, $organization, $whisky);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return iterable<string, array{\Closure(User, Organization, string, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::list($user, $organization)];
        yield 'GET item' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::get($user, $organization, $id)];
        yield 'POST' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::create($user, $organization, ['name' => 'Beer', 'measure' => 'volume'])];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::update($user, $organization, $id, ['name' => 'Beer'])];
        yield 'merge' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::merge($user, $organization, $id, ['into' => $other])];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id, string $other): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_products_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $product = self::addProduct($organization, 'Cola');
        $other = self::addProduct($organization, 'Fanta');
        self::linkItems($organization, $product, 1);
        $before = self::list($owner, $organization)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $product->getId()->toRfc4122(), $other->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization)->toArray());
        self::assertSame(1, self::countLinked($product));
    }

    public function test_it_refuses_an_employee_before_looking_at_the_product(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::update($employee, $organization, $unknown, ['name' => 'Food']);
        self::assertResponseStatusCodeSame(403);
        self::delete($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::merge($employee, $organization, $unknown, ['into' => Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_requires_a_signed_in_user_and_a_selected_organization(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', '/api/products');
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', '/api/products');
        self::assertResponseStatusCodeSame(400);
    }

    /** @param array<string, string> $query */
    private static function list(User $user, Organization $organization, array $query = []): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/products', ['query' => $query]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/products/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function create(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/products', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function update(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/products/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    private static function deleteCategory(User $user, Organization $organization, ProductCategory $category): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/product-categories/'.$category->getId()->toRfc4122());
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/products/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function merge(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/products/'.$id.'/merge', ['json' => $body]);
    }

    private static function addCategory(Organization $organization, string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $owner = OrganizationMother::ownerOf($organization);
        $category = null;

        self::inTenant($organization, static function () use ($name, $parent, $owner, &$category): void {
            $categories = static::getContainer()->get(ProductCategoryRepositoryInterface::class);
            $category = ProductCategory::create(
                Uuid::v7(),
                $name,
                null === $parent ? null : $categories->findById($parent->getId()),
                null,
                $owner->getId(),
                MembershipRole::Owner,
                Clock::get()->now(),
            );
            $categories->add($category);
        });

        return $category ?? throw new \LogicException('Category expected.');
    }

    private static function addProduct(Organization $organization, string $name, ?ProductCategory $category = null, ProductMeasure $measure = ProductMeasure::Volume, ?string $referenceQuantity = null, bool $excluded = false): Product
    {
        $owner = OrganizationMother::ownerOf($organization);
        $product = null;

        self::inTenant($organization, static function () use ($name, $category, $measure, $referenceQuantity, $excluded, $owner, &$product): void {
            $categories = static::getContainer()->get(ProductCategoryRepositoryInterface::class);
            $products = static::getContainer()->get(ProductRepositoryInterface::class);
            $product = Product::create(
                Uuid::v7(),
                $name,
                null === $category ? null : $categories->findById($category->getId()),
                $measure,
                $referenceQuantity,
                $excluded,
                null,
                $owner->getId(),
                MembershipRole::Owner,
                Clock::get()->now(),
            );
            $products->add($product);
        });

        return $product ?? throw new \LogicException('Product expected.');
    }

    /**
     * Records items in the organization and links them to the product the way #242 will: its
     * product, status and package size.
     *
     * @return list<string> the items' ids
     */
    private static function linkItems(Organization $organization, Product $product, int $count, string $status = 'confirmed', string $packageSize = '1'): array
    {
        $ids = [];

        self::inTenant($organization, static function () use ($count, &$ids): void {
            $items = static::getContainer()->get(PurchasedItemRepositoryInterface::class);
            $invoiceId = Uuid::v7();
            $recorded = [];
            for ($index = 1; $index <= $count; ++$index) {
                $recorded[] = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
                    $invoiceId, $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                    $index, 'Item '.$index, null, null, 'szt.', '1', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
                ), Clock::get()->now());
                $ids[] = $recorded[$index - 1]->getId()->toRfc4122();
            }
            $items->addAll($recorded);
        });
        $connection = static::getContainer()->get(Connection::class);
        foreach ($ids as $id) {
            $connection->executeStatement('UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size WHERE id = :id', [
                'product' => $product->getId()->toRfc4122(),
                'status' => $status,
                'size' => $packageSize,
                'id' => $id,
            ]);
        }

        return $ids;
    }

    /** @return array<string, array{string, string, string}> product id, status and package size by item id */
    private static function linkedItems(): array
    {
        $rows = static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT id, product_id, status, package_size FROM purchased_item WHERE product_id IS NOT NULL');

        return array_column(array_map(static fn (array $row): array => [$row['id'], [$row['product_id'], $row['status'], $row['package_size']]], $rows), 1, 0);
    }

    private static function countLinked(Product $product): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM purchased_item WHERE product_id = :product', ['product' => $product->getId()->toRfc4122()]);
    }

    /** @return list<Envelope> what the last request queued on the in-memory `jobs` transport (ADR 0025 §7) */
    private static function queuedJobs(): array
    {
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            // A request starts with an empty identity map: an arranged entity must not be found by
            // id there without the TenantFilter (EntityManager::find() reads the map first).
            $entityManager->clear();
        }
    }
}
