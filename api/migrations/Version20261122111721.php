<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How end-of-day closes get their date, one row per organization at most (#190, ADR 0033 §5). No
 * rows are added: an organization without one uses the defaults (day_end_time, 05:00).
 */
final class Version20261122111721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Day-close settings (#190, ADR 0033 §5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_day_close_settings (id UUID NOT NULL, mode VARCHAR(16) NOT NULL, day_ends_at VARCHAR(5) NOT NULL, updated_by UUID NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX cash_day_close_settings_organization_unique ON cash_day_close_settings (organization_id)');
        $this->addSql('ALTER TABLE cash_day_close_settings ADD CONSTRAINT FK_29D76BE032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_day_close_settings DROP CONSTRAINT FK_29D76BE032C8A3DE');
        $this->addSql('DROP TABLE cash_day_close_settings');
    }
}
