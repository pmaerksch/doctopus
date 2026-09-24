<?php

declare(strict_types=1);

namespace Doctopus\Tests\Unit;

use Doctopus\Platform\PlatformDetector;
use Doctopus\Platform\SupportedPlatform;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


final class PlatformDetectorTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, ?string}>
	 */
	public static function versions(): iterable
	{
		yield 'mysql'             => [ '8.4.3', '8.4.3' ];
		yield 'mysql distro'      => [ '8.0.39-0ubuntu0.24.04.2', '8.0.39' ];
		yield 'mariadb'           => [ '11.4.5-MariaDB-ubu2404', '11.4.5' ];
		yield 'mariadb 5.5.5 hack'=> [ '5.5.5-10.6.12-MariaDB-log', '10.6.12' ];
		yield 'sqlite'            => [ '3.45.1', '3.45.1' ];
		yield 'garbage'           => [ 'unknown', null ];
	}



	#[DataProvider('versions')]
	public function testNormalizesVersions(string $raw, ?string $expected): void
	{
		self::assertSame($expected, PlatformDetector::normalizeVersion($raw));
	}



	public function testMapsDbalPlatforms(): void
	{
		self::assertSame(SupportedPlatform::MYSQL, SupportedPlatform::fromDbalPlatform(new MySQLPlatform()));
		self::assertSame(SupportedPlatform::MARIADB, SupportedPlatform::fromDbalPlatform(new MariaDBPlatform()));
		self::assertSame(SupportedPlatform::SQLITE, SupportedPlatform::fromDbalPlatform(new SQLitePlatform()));
		self::assertNull(SupportedPlatform::fromDbalPlatform(new PostgreSQLPlatform()));
	}



	public function testDetectsSqlite(): void
	{
		$connection = DriverManager::getConnection([ 'driver' => 'pdo_sqlite', 'memory' => true ]);
		$detected   = (new PlatformDetector())->detect($connection);

		self::assertSame(SupportedPlatform::SQLITE, $detected->platform);
		self::assertSame(SupportedPlatform::SQLITE, $detected->dbalPlatform);
		self::assertFalse($detected->hasPlatformMismatch());
		self::assertMatchesRegularExpression('/^3\.\d+/', (string) $detected->version);
	}
}
