<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125003538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'KSeF connection: the pending package export, and the last import\'s method and backlog (ADR 0039)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ksef_connection ADD last_sync_method VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD import_backlog_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD import_backlog_is_minimum BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE ksef_connection ALTER import_backlog_is_minimum DROP DEFAULT');
        $this->addSql('ALTER TABLE ksef_connection ADD export_reference_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD export_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD export_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD export_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD export_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ksef_connection DROP last_sync_method');
        $this->addSql('ALTER TABLE ksef_connection DROP import_backlog_count');
        $this->addSql('ALTER TABLE ksef_connection DROP import_backlog_is_minimum');
        $this->addSql('ALTER TABLE ksef_connection DROP export_reference_number');
        $this->addSql('ALTER TABLE ksef_connection DROP export_key_id');
        $this->addSql('ALTER TABLE ksef_connection DROP export_from');
        $this->addSql('ALTER TABLE ksef_connection DROP export_to');
        $this->addSql('ALTER TABLE ksef_connection DROP export_started_at');
    }
}
