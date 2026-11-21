<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Payment methods per organization (#110, ADR 0030). Every existing organization gets the four
 * methods of ADR 0022 §4 as its initial list, in that order and named by its country (as
 * App\Income\Domain\Model\DefaultPaymentMethods names them today; copied here, so later changes of
 * the defaults do not change this migration). Each existing payment line then refers to the
 * organization's method that replaces its enum value, so every amount and total stays the same.
 */
final class Version20261121231203 extends AbstractMigration
{
    /** enum value => [position, English name, Polish name] */
    private const array METHODS = [
        'cash' => [0, 'Cash', 'Gotówka'],
        'card' => [1, 'Card', 'Karta'],
        'transfer' => [2, 'Transfer', 'Przelew'],
        'voucher' => [3, 'Voucher', 'Bon'],
    ];

    public function getDescription(): string
    {
        return 'Payment methods per organization (#110, ADR 0030): the four former enum values become each organization\'s methods, and payment lines refer to them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_method (id UUID NOT NULL, name VARCHAR(64) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX payment_method_name_unique ON payment_method (organization_id, name)');
        $this->addSql('CREATE INDEX IDX_7B61A1F632C8A3DE ON payment_method (organization_id)');
        $this->addSql('ALTER TABLE payment_method ADD CONSTRAINT FK_7B61A1F632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // The initial list of every organization; UUID v7 as the application generates them (ADR 0009).
        /** @var list<array{id: string, country_code: string}> $organizations */
        $organizations = $this->connection->fetchAllAssociative('SELECT id, country_code FROM organization ORDER BY id');
        foreach ($organizations as $organization) {
            foreach (self::METHODS as [$position, $english, $polish]) {
                $this->addSql(
                    "INSERT INTO payment_method (id, name, position, created_at, deactivated_at, organization_id) VALUES (:id, :name, :position, (NOW() AT TIME ZONE 'UTC'), NULL, :organizationId)",
                    [
                        'id' => Uuid::v7()->toRfc4122(),
                        'name' => 'PL' === strtoupper($organization['country_code']) ? $polish : $english,
                        'position' => $position,
                        'organizationId' => $organization['id'],
                    ],
                );
            }
        }

        // Each line refers to its organization's method at the position of its enum value.
        $this->addSql('ALTER TABLE revenue_entry_payment_line ADD payment_method_id UUID DEFAULT NULL');
        $this->addSql(\sprintf(
            'UPDATE revenue_entry_payment_line l SET payment_method_id = m.id FROM payment_method m WHERE m.organization_id = l.organization_id AND m.position = CASE l.method %s END',
            implode(' ', array_map(static fn (string $method, array $row): string => \sprintf("WHEN '%s' THEN %d", $method, $row[0]), array_keys(self::METHODS), self::METHODS)),
        ));
        // Fails (and the transaction rolls back) if a line had a value outside the enum.
        $this->addSql('ALTER TABLE revenue_entry_payment_line ALTER payment_method_id SET NOT NULL');

        $this->addSql('ALTER TABLE revenue_entry_payment_line DROP CONSTRAINT revenue_entry_payment_line_pkey');
        $this->addSql('ALTER TABLE revenue_entry_payment_line DROP method');
        // No ON DELETE: a method that a line refers to cannot be deleted (ADR 0030 §4).
        $this->addSql('ALTER TABLE revenue_entry_payment_line ADD CONSTRAINT FK_C5B889805AA1164F FOREIGN KEY (payment_method_id) REFERENCES payment_method (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX revenue_entry_payment_line_payment_method ON revenue_entry_payment_line (payment_method_id)');
        $this->addSql('ALTER TABLE revenue_entry_payment_line ADD PRIMARY KEY (revenue_entry_id, payment_method_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Methods an organization added, renamed or reordered have no enum value to go back to.');
    }
}
