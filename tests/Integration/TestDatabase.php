<?php

declare(strict_types=1);

namespace Doctopus\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;


/**
 * Connects to the database under test (DATABASE_URL) and wipes the fixture tables.
 */
final class TestDatabase
{
	public static function url(): string
	{
		$url = getenv('DATABASE_URL');

		return is_string($url) && $url !== '' ? $url : 'sqlite:///:memory:';
	}



	public static function connect(): Connection
	{
		$parser = new DsnParser([
			'mysql'   => 'pdo_mysql',
			'mariadb' => 'pdo_mysql',
			'sqlite'  => 'pdo_sqlite',
		]);

		$connection = DriverManager::getConnection($parser->parse(self::url()));
		self::reset($connection);

		return $connection;
	}



	public static function reset(Connection $connection): void
	{
		$schemaManager = $connection->createSchemaManager();

		foreach ( [ 'doctopus_booking', 'doctopus_customer', 'doctrine_migration_versions' ] as $table )
		{
			if ( $schemaManager->tablesExist([ $table ]) )
			{
				$schemaManager->dropTable($table);
			}
		}
	}
}
