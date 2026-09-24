<?php

declare(strict_types=1);

namespace Doctopus\Migration;

use Doctopus\Platform\DetectedPlatform;
use Doctopus\Platform\PlatformDetector;
use Doctopus\Platform\PlatformPolicy;
use Doctopus\Platform\SupportedPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;


/**
 * Base class for migrations that run unchanged on every supported database.
 *
 * Modify the given $schema in up()/down() instead of writing DDL with addSql():
 * Doctrine diffs the modified schema against the current one and renders the SQL
 * for whatever platform the migration is executed on.
 *
 * If you override preUp() or preDown(), call the parent so the platform check still runs.
 */
abstract class PortableMigration extends AbstractMigration
{
	private ?PlatformPolicy $platformPolicy     = null;
	private ?DetectedPlatform $detectedPlatform = null;



	/**
	 * @internal Called by the migration factory with the application's configured policy.
	 */
	public function setPlatformPolicy(PlatformPolicy $policy): void
	{
		$this->platformPolicy = $policy;
	}



	public function preUp(Schema $schema): void
	{
		$this->assertSupportedPlatform();
	}



	public function preDown(Schema $schema): void
	{
		$this->assertSupportedPlatform();
	}



	/**
	 * MySQL and MariaDB implicitly commit on every DDL statement, so wrapping their
	 * migrations in a transaction only produces "no active transaction" errors.
	 * SQLite has transactional DDL, so it keeps the all-or-nothing behaviour.
	 */
	public function isTransactional(): bool
	{
		$platform = $this->detectedPlatform()->dbalPlatform;

		if ( $platform === null )
		{
			return parent::isTransactional();
		}

		return !$platform->isMysqlFamily();
	}



	/**
	 * Aborts (strict policy) or warns (lenient policy) when the database is not one the application supports.
	 */
	protected function assertSupportedPlatform(): void
	{
		$policy     = $this->platformPolicy();
		$detected   = $this->detectedPlatform();
		$violations = $policy->violations($detected);

		if ( $violations !== [] && $policy->strict )
		{
			$this->abortIf(true, implode(' ', $violations));
		}

		foreach ( [ ...$violations, ...$policy->warnings($detected) ] as $message )
		{
			$this->warnIf(true, $message);
		}
	}



	/**
	 * The platform DBAL renders SQL for. Throws if it is not a supported one.
	 */
	protected function databasePlatform(): SupportedPlatform
	{
		$detected = $this->detectedPlatform();

		if ( $detected->dbalPlatform === null )
		{
			throw new AbortMigration(sprintf('Unsupported database platform: %s', $detected->platformClass));
		}

		return $detected->dbalPlatform;
	}



	protected function isSqlite(): bool
	{
		return $this->detectedPlatform()->dbalPlatform === SupportedPlatform::SQLITE;
	}



	protected function isMysql(): bool
	{
		return $this->detectedPlatform()->dbalPlatform === SupportedPlatform::MYSQL;
	}



	protected function isMariaDb(): bool
	{
		return $this->detectedPlatform()->dbalPlatform === SupportedPlatform::MARIADB;
	}



	/**
	 * True for MySQL and MariaDB.
	 */
	protected function isMysqlFamily(): bool
	{
		return $this->detectedPlatform()->dbalPlatform?->isMysqlFamily() ?? false;
	}



	/**
	 * Adds raw SQL for the current platform, for the rare cases the Schema API cannot express.
	 *
	 * Pass null for a platform to say "this migration has no SQL for it" (the migration aborts
	 * there), or an empty string to say "nothing to do on this platform". The MariaDB SQL
	 * falls back to the MySQL SQL when it is not given.
	 *
	 * @param array<int|string, mixed> $params
	 * @param array<int|string, mixed> $types
	 */
	protected function addPlatformSql(
		?string $mysql = null,
		?string $mariadb = null,
		?string $sqlite = null,
		array $params = [],
		array $types = [],
	): void
	{
		$platform = $this->databasePlatform();

		$sql = match ( $platform )
		{
			SupportedPlatform::MYSQL   => $mysql,
			SupportedPlatform::MARIADB => $mariadb ?? $mysql,
			SupportedPlatform::SQLITE  => $sqlite,
		};

		$this->abortIf($sql === null, sprintf('%s provides no SQL for %s.', static::class, $platform->label()));

		if ( $sql === '' )
		{
			return;
		}

		$this->addSql($sql, $params, $types);
	}



	private function platformPolicy(): PlatformPolicy
	{
		return $this->platformPolicy ??= new PlatformPolicy();
	}



	private function detectedPlatform(): DetectedPlatform
	{
		return $this->detectedPlatform ??= (new PlatformDetector())->detect($this->connection);
	}
}
