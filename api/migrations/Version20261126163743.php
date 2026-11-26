<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What matching linked an item by and why it only suggested it (ADR 0045 §1): two nullable columns, empty for the items matched before. */
final class Version20261126163743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchased item: match source and caveat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item ADD match_source VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD match_caveat VARCHAR(24) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item DROP match_source');
        $this->addSql('ALTER TABLE purchased_item DROP match_caveat');
    }
}
