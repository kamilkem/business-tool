<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112232802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expenses: expense categories per organization (#48)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense_category (id UUID NOT NULL, name VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX expense_category_name_unique ON expense_category (organization_id, name)');
        $this->addSql('CREATE INDEX IDX_C02DDB3832C8A3DE ON expense_category (organization_id)');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT FK_C02DDB3832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense_category DROP CONSTRAINT FK_C02DDB3832C8A3DE');
        $this->addSql('DROP TABLE expense_category');
    }
}
