<?php

declare(strict_types=1);

namespace Doctopus\Platform;

use Doctrine\DBAL\Connection;
use Throwable;


/**
 * Detects which supported platform (and which server version) a connection talks to.
 */
final class PlatformDetector
{
	public function detect(Connection $connection): DetectedPlatform
	{
		$dbalPlatform  = $connection->getDatabasePlatform();
		$supported     = SupportedPlatform::fromDbalPlatform($dbalPlatform);
		$rawVersion    = $this->queryVersion($connection, $supported);
		$platform      = $supported;

		// The server knows better than the configured "serverVersion" whether it is MariaDB.
		if ( $supported !== null && $supported->isMysqlFamily() && $rawVersion !== null )
		{
			$platform = stripos($rawVersion, 'mariadb') !== false ? SupportedPlatform::MARIADB : SupportedPlatform::MYSQL;
		}

		return new DetectedPlatform(
			$platform,
			$supported,
			$dbalPlatform::class,
			$rawVersion !== null ? self::normalizeVersion($rawVersion) : null,
			$rawVersion,
		);
	}



	/**
	 * Turns "5.5.5-10.6.12-MariaDB-log" or "8.4.3-0ubuntu0" into "10.6.12" / "8.4.3".
	 */
	public static function normalizeVersion(string $rawVersion): ?string
	{
		// MariaDB used to prefix its version with a fake "5.5.5-" for replication compatibility.
		$version = preg_replace('/^5\.5\.5-/', '', trim($rawVersion));

		if ( preg_match('/^(\d+(?:\.\d+){0,2})/', (string) $version, $match) !== 1 )
		{
			return null;
		}

		return $match[1];
	}



	private function queryVersion(Connection $connection, ?SupportedPlatform $platform): ?string
	{
		$sql = match ( $platform )
		{
			SupportedPlatform::SQLITE => 'SELECT sqlite_version()',
			SupportedPlatform::MYSQL, SupportedPlatform::MARIADB => 'SELECT VERSION()',
			null => null,
		};

		if ( $sql === null )
		{
			return null;
		}

		try
		{
			$version = $connection->fetchOne($sql);
		}
		catch ( Throwable )
		{
			return null;
		}

		return is_string($version) && $version !== '' ? $version : null;
	}
}
