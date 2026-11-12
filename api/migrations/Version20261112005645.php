<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112005645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shift change requests: members ask owners and managers to enter or change their shifts (#84)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shift_change_request (id UUID NOT NULL, user_id UUID NOT NULL, kind VARCHAR(16) NOT NULL, shift_id UUID DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note TEXT NOT NULL, status VARCHAR(16) NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_by UUID DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX shift_change_request_organization_submitted_at ON shift_change_request (organization_id, submitted_at)');
        $this->addSql('CREATE INDEX IDX_DE7B6BD32C8A3DE ON shift_change_request (organization_id)');
        $this->addSql('ALTER TABLE shift_change_request ADD CONSTRAINT FK_DE7B6BD32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_change_request DROP CONSTRAINT FK_DE7B6BD32C8A3DE');
        $this->addSql('DROP TABLE shift_change_request');
    }
}
