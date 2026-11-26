<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The product the model proposes for an item no product fits, kept on the item (ADR 0044).
 */
final class Version20261126115344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: the product proposed for an unmatched item, and the proposal a person dismissed (ADR 0044)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item ADD proposed_name VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD proposed_name_key VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD proposed_measure VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD dismissed_proposal_key VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD proposed_category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD CONSTRAINT FK_F8482141E8FD27AE FOREIGN KEY (proposed_category_id) REFERENCES product_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F8482141E8FD27AE ON purchased_item (proposed_category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item DROP CONSTRAINT FK_F8482141E8FD27AE');
        $this->addSql('DROP INDEX IDX_F8482141E8FD27AE');
        $this->addSql('ALTER TABLE purchased_item DROP proposed_name');
        $this->addSql('ALTER TABLE purchased_item DROP proposed_name_key');
        $this->addSql('ALTER TABLE purchased_item DROP proposed_measure');
        $this->addSql('ALTER TABLE purchased_item DROP dismissed_proposal_key');
        $this->addSql('ALTER TABLE purchased_item DROP proposed_category_id');
    }
}
