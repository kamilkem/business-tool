<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Purchasing module's products (#241, ADR 0041 §4), and the foreign key that purchased items'
 * `product_id` (null until #242) was waiting for. No data to copy: nothing refers to a product yet.
 */
final class Version20261125143225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: products, and the foreign key from purchased items (#241, ADR 0041 §4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, name VARCHAR(128) NOT NULL, name_key VARCHAR(128) NOT NULL, measure VARCHAR(16) NOT NULL, reference_quantity NUMERIC(22, 6) DEFAULT NULL, excluded BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category_id UUID DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX product_name_unique ON product (organization_id, name_key)');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD32C8A3DE ON product (organization_id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES product_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchased_item ADD CONSTRAINT FK_F84821414584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F84821414584665A ON purchased_item (product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item DROP CONSTRAINT FK_F84821414584665A');
        $this->addSql('DROP INDEX IDX_F84821414584665A');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD32C8A3DE');
        $this->addSql('DROP TABLE product');
    }
}
