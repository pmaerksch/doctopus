<?php

declare(strict_types=1);

namespace Doctopus\Tests\Integration;

use Doctopus\Migration\PortableMigrationFactory;
use Doctopus\Platform\PlatformPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Doctrine\Migrations\Version\MigrationFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;


/**
 * Runs the fixture migrations up and down against DATABASE_URL (SQLite in memory by default).
 */
final class PortableMigrationTest extends TestCase
{
	private Connection $connection;



	protected function setUp(): void
	{
		$this->connection = TestDatabase::connect();
	}



	protected function tearDown(): void
	{
		TestDatabase::reset($this->connection);
		$this->connection->close();
	}



	public function testMigratesUpAndDownOnTheCurrentPlatform(): void
	{
		$this->migrate(new PlatformPolicy(), 'latest');

		$schemaManager = $this->connection->createSchemaManager();

		self::assertTrue($schemaManager->tablesExist([ 'doctopus_customer', 'doctopus_booking' ]));
		self::assertTrue($schemaManager->introspectTable('doctopus_customer')->hasColumn('active'));
		self::assertSame('Ada <ada@example.org>', $this->connection->fetchOne('SELECT name FROM doctopus_customer'));

		$this->migrate(new PlatformPolicy(), 'first');

		self::assertFalse($schemaManager->tablesExist([ 'doctopus_customer' ]));
		self::assertFalse($schemaManager->tablesExist([ 'doctopus_booking' ]));
	}



	public function testAbortsOnPlatformTheApplicationDoesNotSupport(): void
	{
		$this->expectException(AbortMigration::class);

		$this->migrate(new PlatformPolicy($this->otherPlatform()), 'latest');
	}



	public function testLenientPolicyOnlyWarns(): void
	{
		$this->migrate(new PlatformPolicy($this->otherPlatform(), strict: false), 'latest');

		self::assertTrue($this->connection->createSchemaManager()->tablesExist([ 'doctopus_customer' ]));
	}



	private function migrate(PlatformPolicy $policy, string $alias): void
	{
		$configuration = new Configuration();
		$configuration->addMigrationsDirectory('Doctopus\Tests\Fixtures\Migrations', __DIR__ . '/../Fixtures/Migrations');
		$configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
		$configuration->setAllOrNothing(false);

		$dependencyFactory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->connection));
		$inner             = new DbalMigrationFactory($this->connection, new NullLogger());

		$dependencyFactory->setService(MigrationFactory::class, new PortableMigrationFactory($inner, $policy));
		$dependencyFactory->getMetadataStorage()->ensureInitialized();

		$version = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias($alias);
		$plan    = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($version);

		$dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
	}



	/**
	 * A policy that supports every platform except the one under test.
	 *
	 * @return array<string, string>
	 */
	private function otherPlatform(): array
	{
		return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? [ 'mysql' => '8.0' ] : [ 'sqlite' => '3.35' ];
	}
}
