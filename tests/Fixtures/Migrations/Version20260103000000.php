<?php

declare(strict_types=1);

namespace Doctopus\Tests\Fixtures\Migrations;

use Doctopus\Migration\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;


/**
 * Data lives in its own migration: Doctrine runs addSql() statements before the SQL
 * generated from $schema changes, so they cannot rely on columns added in the same migration.
 */
final class Version20260103000000 extends PortableMigration
{
	public function getDescription(): string
	{
		return 'Seed a customer';
	}



	public function up(Schema $schema): void
	{
		// Portable DML is fine in addSql().
		$this->addSql(
			'INSERT INTO doctopus_customer (email, name, active) VALUES (?, ?, ?)',
			[ 'ada@example.org', 'Ada', true ],
			[ Types::STRING, Types::STRING, Types::BOOLEAN ],
		);

		// Deliberately platform-specific SQL goes through addPlatformSql().
		$this->addPlatformSql(
			mysql: "UPDATE doctopus_customer SET name = CONCAT(name, ' <', email, '>')",
			sqlite: "UPDATE doctopus_customer SET name = name || ' <' || email || '>'",
		);
	}



	public function down(Schema $schema): void
	{
		$this->addSql('DELETE FROM doctopus_customer WHERE email = ?', [ 'ada@example.org' ]);
	}
}
