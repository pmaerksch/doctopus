<?php

declare(strict_types=1);

namespace Doctopus\Platform;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;


/**
 * The database platforms doctopus knows how to keep migrations portable across.
 */
enum SupportedPlatform: string
{
	case MYSQL   = 'mysql';
	case MARIADB = 'mariadb';
	case SQLITE  = 'sqlite';



	/**
	 * Maps a DBAL platform instance to a supported platform, or null if it is none of them.
	 * MariaDB is checked first because older DBAL versions derive it from the MySQL platform.
	 */
	public static function fromDbalPlatform(AbstractPlatform $platform): ?self
	{
		if ( $platform instanceof MariaDBPlatform )
		{
			return self::MARIADB;
		}

		if ( $platform instanceof MySQLPlatform )
		{
			return self::MYSQL;
		}

		if ( $platform instanceof SQLitePlatform )
		{
			return self::SQLITE;
		}

		return null;
	}



	public function label(): string
	{
		return match ( $this )
		{
			self::MYSQL   => 'MySQL',
			self::MARIADB => 'MariaDB',
			self::SQLITE  => 'SQLite',
		};
	}



	/**
	 * MySQL and MariaDB share most of their SQL dialect and their DDL behaviour.
	 */
	public function isMysqlFamily(): bool
	{
		return $this === self::MYSQL || $this === self::MARIADB;
	}
}
