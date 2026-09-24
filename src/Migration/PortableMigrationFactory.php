<?php

declare(strict_types=1);

namespace Doctopus\Migration;

use Doctopus\Platform\PlatformPolicy;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\MigrationFactory;


/**
 * Decorates Doctrine's migration factory to hand the configured platform policy to portable migrations.
 */
final class PortableMigrationFactory implements MigrationFactory
{
	public function __construct(
		private readonly MigrationFactory $inner,
		private readonly PlatformPolicy $policy,
	) {}



	public function createVersion(string $migrationClassName): AbstractMigration
	{
		$migration = $this->inner->createVersion($migrationClassName);

		if ( $migration instanceof PortableMigration )
		{
			$migration->setPlatformPolicy($this->policy);
		}

		return $migration;
	}
}
