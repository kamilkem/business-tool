<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120184511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payroll: mark a paycheck paid with a business date and a method (ADR 0027 §9, #63)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paycheck ADD paid_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE paycheck ADD payment_method VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE paycheck ADD paid_marked_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE paycheck ADD paid_marked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paycheck DROP paid_on');
        $this->addSql('ALTER TABLE paycheck DROP payment_method');
        $this->addSql('ALTER TABLE paycheck DROP paid_marked_by');
        $this->addSql('ALTER TABLE paycheck DROP paid_marked_at');
    }
}
