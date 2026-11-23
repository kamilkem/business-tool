<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123000841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time tracking settings (maximum shift length) and the long-shift report mark on shifts (#33).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE time_tracking_settings (id UUID NOT NULL, max_shift_hours SMALLINT NOT NULL, updated_by UUID NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX time_tracking_settings_organization_unique ON time_tracking_settings (organization_id)');
        $this->addSql('ALTER TABLE time_tracking_settings ADD CONSTRAINT FK_6C7A792532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE shift ADD long_shift_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_tracking_settings DROP CONSTRAINT FK_6C7A792532C8A3DE');
        $this->addSql('DROP TABLE time_tracking_settings');
        $this->addSql('ALTER TABLE shift DROP long_shift_reported_at');
    }
}
