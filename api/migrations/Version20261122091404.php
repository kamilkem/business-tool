<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Denominations per organization (#179, ADR 0033 §2). Every existing organization gets the default
 * denominations of its currency, as App\CashFlow\Domain\Model\DefaultDenominations lists them today
 * (copied here, so later changes of the defaults do not change this migration). A currency with no
 * defaults gets none.
 */
final class Version20261122091404 extends AbstractMigration
{
    /** @var array<string, array{coin: list<int>, note: list<int>}> ISO 4217 => face values in minor units */
    private const array DEFAULTS = [
        'PLN' => [
            'coin' => [1, 2, 5, 10, 20, 50, 100, 200, 500],
            'note' => [1_000, 2_000, 5_000, 10_000, 20_000, 50_000],
        ],
    ];

    public function getDescription(): string
    {
        return 'Denominations per organization (#179, ADR 0033 §2), with the defaults of each existing organization\'s currency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_denomination (id UUID NOT NULL, currency VARCHAR(3) NOT NULL, value INT NOT NULL, kind VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX cash_denomination_value_unique ON cash_denomination (organization_id, currency, value)');
        $this->addSql('CREATE INDEX IDX_969D45CC32C8A3DE ON cash_denomination (organization_id)');
        $this->addSql('ALTER TABLE cash_denomination ADD CONSTRAINT FK_969D45CC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // UUID v7 as the application generates them (ADR 0009).
        /** @var list<array{id: string, currency: string}> $organizations */
        $organizations = $this->connection->fetchAllAssociative('SELECT id, currency FROM organization ORDER BY id');
        foreach ($organizations as $organization) {
            foreach (self::DEFAULTS[strtoupper($organization['currency'])] ?? [] as $kind => $values) {
                foreach ($values as $value) {
                    $this->addSql(
                        "INSERT INTO cash_denomination (id, currency, value, kind, created_at, deactivated_at, organization_id) VALUES (:id, :currency, :value, :kind, (NOW() AT TIME ZONE 'UTC'), NULL, :organizationId)",
                        [
                            'id' => Uuid::v7()->toRfc4122(),
                            'currency' => strtoupper($organization['currency']),
                            'value' => $value,
                            'kind' => $kind,
                            'organizationId' => $organization['id'],
                        ],
                    );
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_denomination DROP CONSTRAINT FK_969D45CC32C8A3DE');
        $this->addSql('DROP TABLE cash_denomination');
    }
}
