<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * The Purchasing module's product categories (#238, ADR 0041 §5). Every existing organization gets
 * the default tree of its country (as App\Purchasing\Domain\Model\DefaultProductCategories lists it
 * today; copied here, so later changes of the defaults do not change this migration).
 */
final class Version20261125115932 extends AbstractMigration
{
    /** @var list<array{string, string, list<array{string, string}>}> [English name, Polish name, [[English, Polish] subcategory, ...]] */
    private const array DEFAULTS = [
        ['Alcohol', 'Alkohol', [['Vodka', 'Wódka'], ['Whisky', 'Whisky'], ['Rum', 'Rum'], ['Gin', 'Gin'], ['Liqueurs', 'Likiery'], ['Wine', 'Wino'], ['Beer', 'Piwo']]],
        ['Soft drinks', 'Napoje bezalkoholowe', [['Juices', 'Soki'], ['Fizzy drinks', 'Napoje gazowane'], ['Water', 'Woda'], ['Syrups', 'Syropy']]],
        ['Coffee and tea', 'Kawa i herbata', []],
        ['Food', 'Żywność', []],
        ['Cleaning and chemicals', 'Chemia i środki czystości', []],
        ['Packaging and disposables', 'Opakowania i artykuły jednorazowe', []],
        ['Equipment', 'Wyposażenie', []],
        ['Utilities', 'Media', [['Electricity', 'Prąd'], ['Gas', 'Gaz'], ['Water', 'Woda'], ['Internet and phone', 'Internet i telefon']]],
        ['Rent', 'Czynsz', []],
        ['Services', 'Usługi', [['Transport', 'Transport'], ['Maintenance', 'Serwis'], ['Accounting', 'Księgowość']]],
        ['Deposits', 'Kaucje', []],
        ['Other', 'Inne', []],
    ];

    public function getDescription(): string
    {
        return 'Purchasing: product categories, with the default tree for each existing organization (#238, ADR 0041 §5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_category (id UUID NOT NULL, name VARCHAR(64) NOT NULL, name_key VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, parent_id UUID DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX product_category_name_under_parent_unique ON product_category (organization_id, parent_id, name_key)');
        $this->addSql('CREATE UNIQUE INDEX product_category_name_at_top_level_unique ON product_category (organization_id, name_key) WHERE (parent_id IS NULL)');
        $this->addSql('CREATE INDEX IDX_CDFC7356727ACA70 ON product_category (parent_id)');
        $this->addSql('CREATE INDEX IDX_CDFC735632C8A3DE ON product_category (organization_id)');
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT FK_CDFC7356727ACA70 FOREIGN KEY (parent_id) REFERENCES product_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT FK_CDFC735632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // The initial tree of every organization; UUID v7 as the application generates them (ADR 0009).
        /** @var list<array{id: string, country_code: string}> $organizations */
        $organizations = $this->connection->fetchAllAssociative('SELECT id, country_code FROM organization ORDER BY id');
        foreach ($organizations as $organization) {
            $polish = 'PL' === strtoupper($organization['country_code']);
            foreach (self::DEFAULTS as [$english, $polishName, $children]) {
                $parentId = Uuid::v7()->toRfc4122();
                $this->insertCategory($parentId, $polish ? $polishName : $english, null, $organization['id']);
                foreach ($children as [$childEnglish, $childPolish]) {
                    $this->insertCategory(Uuid::v7()->toRfc4122(), $polish ? $childPolish : $childEnglish, $parentId, $organization['id']);
                }
            }
        }
    }

    private function insertCategory(string $id, string $name, ?string $parentId, string $organizationId): void
    {
        $this->addSql(
            "INSERT INTO product_category (id, name, name_key, parent_id, created_at, organization_id) VALUES (:id, :name, :nameKey, :parentId, (NOW() AT TIME ZONE 'UTC'), :organizationId)",
            ['id' => $id, 'name' => $name, 'nameKey' => mb_strtolower($name), 'parentId' => $parentId, 'organizationId' => $organizationId],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_category DROP CONSTRAINT FK_CDFC7356727ACA70');
        $this->addSql('ALTER TABLE product_category DROP CONSTRAINT FK_CDFC735632C8A3DE');
        $this->addSql('DROP TABLE product_category');
    }
}
