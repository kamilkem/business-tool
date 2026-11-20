<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120023111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual expenses (#54): the invoice number and the seller tax ID become optional';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice ALTER invoice_number DROP NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoice ALTER seller_tax_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice ALTER invoice_number SET NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoice ALTER seller_tax_id SET NOT NULL');
    }
}
