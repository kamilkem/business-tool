<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123090430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Income months closed by owners and their closes and reopens, and the change history of revenue entries (#112, ADR 0034).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE revenue_entry_change (id UUID NOT NULL, entry_id UUID NOT NULL, register_id UUID NOT NULL, register_name VARCHAR(64) NOT NULL, business_date DATE NOT NULL, currency VARCHAR(3) NOT NULL, kind VARCHAR(8) NOT NULL, day_close_id UUID DEFAULT NULL, before_snapshot JSON DEFAULT NULL, after_snapshot JSON DEFAULT NULL, changed_by UUID NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX revenue_entry_change_entry ON revenue_entry_change (organization_id, entry_id)');
        $this->addSql('CREATE INDEX revenue_entry_change_business_date ON revenue_entry_change (organization_id, business_date)');
        $this->addSql('CREATE INDEX IDX_11AF0A0A32C8A3DE ON revenue_entry_change (organization_id)');
        $this->addSql('CREATE TABLE revenue_month (id UUID NOT NULL, month DATE NOT NULL, closed_by UUID DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX revenue_month_one_per_month ON revenue_month (organization_id, month)');
        $this->addSql('CREATE INDEX IDX_58A7386832C8A3DE ON revenue_month (organization_id)');
        $this->addSql('CREATE TABLE revenue_month_action (id UUID NOT NULL, kind VARCHAR(8) NOT NULL, actor_id UUID NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, month_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_22318898A0CBDE4 ON revenue_month_action (month_id)');
        $this->addSql('CREATE INDEX IDX_2231889832C8A3DE ON revenue_month_action (organization_id)');
        $this->addSql('ALTER TABLE revenue_entry_change ADD CONSTRAINT FK_11AF0A0A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_month ADD CONSTRAINT FK_58A7386832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_month_action ADD CONSTRAINT FK_22318898A0CBDE4 FOREIGN KEY (month_id) REFERENCES revenue_month (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_month_action ADD CONSTRAINT FK_2231889832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revenue_entry_change DROP CONSTRAINT FK_11AF0A0A32C8A3DE');
        $this->addSql('ALTER TABLE revenue_month DROP CONSTRAINT FK_58A7386832C8A3DE');
        $this->addSql('ALTER TABLE revenue_month_action DROP CONSTRAINT FK_22318898A0CBDE4');
        $this->addSql('ALTER TABLE revenue_month_action DROP CONSTRAINT FK_2231889832C8A3DE');
        $this->addSql('DROP TABLE revenue_entry_change');
        $this->addSql('DROP TABLE revenue_month');
        $this->addSql('DROP TABLE revenue_month_action');
    }
}
