<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store for published NBP table A quotations - public market data only.
 *
 * The platform check accepts the whole MySQL family rather than the exact
 * MySQL 8.4 platform the SQL was generated on, so a server running MariaDB
 * migrates as well.
 */
final class Version20261008102307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NBP table A quotations (nbp_rate) and how far each quarter is stored (nbp_coverage)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('CREATE TABLE nbp_rate (id INT AUTO_INCREMENT NOT NULL, currency CHAR(3) NOT NULL, effective_date DATE NOT NULL, mid VARCHAR(20) NOT NULL, table_no VARCHAR(32) DEFAULT NULL, UNIQUE INDEX uniq_nbp_rate_currency_day (currency, effective_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nbp_coverage (quarter VARCHAR(7) NOT NULL, covered_through DATE NOT NULL, PRIMARY KEY (quarter)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP TABLE nbp_coverage');
        $this->addSql('DROP TABLE nbp_rate');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
