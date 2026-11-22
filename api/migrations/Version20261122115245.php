<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A payment method can be marked as cash (#184, ADR 0033 §9), at most one active method per
 * organization. Each organization's default cash method, as Version20261121231203 named it by its
 * country ("Gotówka" in Poland, "Cash" elsewhere), gets the mark if it still has that name. Names
 * are unique in an organization, so each gets at most one.
 */
final class Version20261122115245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment methods: the cash flag (#184, ADR 0033 §9), set on each organization\'s default cash method';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_method ADD cash BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX payment_method_one_active_cash ON payment_method (organization_id) WHERE ((cash = true) AND (deactivated_at IS NULL))');
        $this->addSql("UPDATE payment_method m SET cash = true FROM organization o WHERE o.id = m.organization_id AND m.name = CASE WHEN UPPER(o.country_code) = 'PL' THEN 'Gotówka' ELSE 'Cash' END");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX payment_method_one_active_cash');
        $this->addSql('ALTER TABLE payment_method DROP cash');
    }
}
