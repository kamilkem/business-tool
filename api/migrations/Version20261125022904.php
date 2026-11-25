<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125022904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase invoices lose their category and the suggestion flag (#228); existing assignments are dropped, not copied';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice DROP category_id');
        $this->addSql('ALTER TABLE purchase_invoice DROP category_suggested');
    }

    public function down(Schema $schema): void
    {
        // The assignments themselves cannot be restored: every invoice comes back uncategorized.
        $this->addSql('ALTER TABLE purchase_invoice ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoice ADD category_suggested BOOLEAN DEFAULT false NOT NULL');
    }
}
