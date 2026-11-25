<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The old flat expense category list goes (#239, ADR 0041 §5). Nothing refers to it since #228, and
 * the product category tree (#238) replaces it, with the default tree of each organization's
 * country already added by Version20261125115932; the names the organizations added or archived
 * here are not copied, because a flat expense category is not a product category.
 */
final class Version20261125133536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The ExpenseCategory table is dropped; the product category tree replaces it (#239, ADR 0041 §5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense_category DROP CONSTRAINT fk_c02ddb3832c8a3de');
        $this->addSql('DROP TABLE expense_category');
    }

    public function down(Schema $schema): void
    {
        // The table comes back empty: the categories it held are not restored.
        $this->addSql('CREATE TABLE expense_category (id UUID NOT NULL, name VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX expense_category_name_unique ON expense_category (organization_id, name)');
        $this->addSql('CREATE INDEX idx_c02ddb3832c8a3de ON expense_category (organization_id)');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT fk_c02ddb3832c8a3de FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
