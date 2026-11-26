<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Automatic matching of purchased items (ADR 0042): the aliases the owner's confirmations leave
 * (`product_alias`, gone with their product), and two columns on `purchased_item`: whether a person
 * took the item off its product (matching leaves it alone) and when the model last went through it.
 * Existing rows are not skipped and were never matched.
 */
final class Version20261125231553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: product aliases and the matching columns of purchased items (ADR 0042)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_alias (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, alias_key VARCHAR(1024) NOT NULL, package_size NUMERIC(22, 6) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, product_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX product_alias_unique_key ON product_alias (organization_id, kind, alias_key)');
        $this->addSql('CREATE INDEX IDX_403010C84584665A ON product_alias (product_id)');
        $this->addSql('CREATE INDEX IDX_403010C832C8A3DE ON product_alias (organization_id)');
        $this->addSql('ALTER TABLE product_alias ADD CONSTRAINT FK_403010C84584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_alias ADD CONSTRAINT FK_403010C832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchased_item ADD matching_skipped BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD matched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_alias DROP CONSTRAINT FK_403010C84584665A');
        $this->addSql('ALTER TABLE product_alias DROP CONSTRAINT FK_403010C832C8A3DE');
        $this->addSql('DROP TABLE product_alias');
        $this->addSql('ALTER TABLE purchased_item DROP matching_skipped');
        $this->addSql('ALTER TABLE purchased_item DROP matched_at');
    }
}
