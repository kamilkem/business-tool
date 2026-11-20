<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113000315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-organization secrets (organization_secret, ADR 0026) and the KSeF connection (ksef_connection, #49)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ksef_connection (id UUID NOT NULL, secret_id UUID DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_check_error VARCHAR(32) DEFAULT NULL, last_check_error_message VARCHAR(1000) DEFAULT NULL, sync_cursor TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_succeeded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_error VARCHAR(32) DEFAULT NULL, last_sync_error_message VARCHAR(1000) DEFAULT NULL, last_sync_imported_count INT NOT NULL, last_sync_skipped_count INT NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX ksef_connection_organization_unique ON ksef_connection (organization_id)');
        $this->addSql('CREATE TABLE organization_secret (id UUID NOT NULL, kind VARCHAR(32) NOT NULL, key_id VARCHAR(64) NOT NULL, nonce TEXT NOT NULL, ciphertext TEXT NOT NULL, set_by UUID NOT NULL, set_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_592C381032C8A3DE ON organization_secret (organization_id)');
        $this->addSql('ALTER TABLE ksef_connection ADD CONSTRAINT FK_289FE43332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_secret ADD CONSTRAINT FK_592C381032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ksef_connection DROP CONSTRAINT FK_289FE43332C8A3DE');
        $this->addSql('ALTER TABLE organization_secret DROP CONSTRAINT FK_592C381032C8A3DE');
        $this->addSql('DROP TABLE ksef_connection');
        $this->addSql('DROP TABLE organization_secret');
    }
}
