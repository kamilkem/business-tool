<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The cash ledger's movements and their lines (#181, ADR 0033 §4). The lines' foreign keys to the
 * storage and the denomination have no ON DELETE, so a used one cannot be deleted.
 */
final class Version20261122104402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash movements and their lines (#181, ADR 0033 §4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_movement (id UUID NOT NULL, kind VARCHAR(10) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, note VARCHAR(255) DEFAULT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX cash_movement_ledger_order ON cash_movement (occurred_at, id)');
        $this->addSql('CREATE INDEX IDX_965E643A32C8A3DE ON cash_movement (organization_id)');
        $this->addSql('CREATE TABLE cash_movement_line (pieces INT NOT NULL, movement_id UUID NOT NULL, storage_id UUID NOT NULL, denomination_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (movement_id, storage_id, denomination_id))');
        $this->addSql('CREATE INDEX cash_movement_line_storage ON cash_movement_line (storage_id)');
        $this->addSql('CREATE INDEX cash_movement_line_denomination ON cash_movement_line (denomination_id)');
        $this->addSql('CREATE INDEX IDX_92DD2AB8229E70A7 ON cash_movement_line (movement_id)');
        $this->addSql('CREATE INDEX IDX_92DD2AB832C8A3DE ON cash_movement_line (organization_id)');
        $this->addSql('ALTER TABLE cash_movement ADD CONSTRAINT FK_965E643A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_movement_line ADD CONSTRAINT FK_92DD2AB8229E70A7 FOREIGN KEY (movement_id) REFERENCES cash_movement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_movement_line ADD CONSTRAINT FK_92DD2AB85CC5DB90 FOREIGN KEY (storage_id) REFERENCES cash_storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_movement_line ADD CONSTRAINT FK_92DD2AB8E9293F06 FOREIGN KEY (denomination_id) REFERENCES cash_denomination (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_movement_line ADD CONSTRAINT FK_92DD2AB832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_movement DROP CONSTRAINT FK_965E643A32C8A3DE');
        $this->addSql('ALTER TABLE cash_movement_line DROP CONSTRAINT FK_92DD2AB8229E70A7');
        $this->addSql('ALTER TABLE cash_movement_line DROP CONSTRAINT FK_92DD2AB85CC5DB90');
        $this->addSql('ALTER TABLE cash_movement_line DROP CONSTRAINT FK_92DD2AB8E9293F06');
        $this->addSql('ALTER TABLE cash_movement_line DROP CONSTRAINT FK_92DD2AB832C8A3DE');
        $this->addSql('DROP TABLE cash_movement');
        $this->addSql('DROP TABLE cash_movement_line');
    }
}
