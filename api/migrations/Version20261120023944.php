<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120023944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jobs runtime (ADR 0025): lock_keys for symfony/lock, cache_items for the stateful schedule; KSeF import status (#52)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cache_items (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
        $this->addSql('ALTER TABLE ksef_connection ADD sync_retry_not_before TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ksef_connection ADD last_import_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('ALTER TABLE ksef_connection DROP sync_retry_not_before');
        $this->addSql('ALTER TABLE ksef_connection DROP last_import_requested_at');
    }
}
