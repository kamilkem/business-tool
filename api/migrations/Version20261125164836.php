<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The review queue lists an organization's purchased items by status, newest issue date first (#242):
 * an index for that. No data changes: the product, status and package size columns exist since #240.
 */
final class Version20261125164836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: an index for listing purchased items by status and issue date (#242)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX purchased_item_by_status_and_date ON purchased_item (organization_id, status, invoice_issue_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX purchased_item_by_status_and_date');
    }
}
