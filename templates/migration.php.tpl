<?php

declare(strict_types=1);

namespace <namespace>;

use Doctopus\Migration\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;


/**
 * Portable migration: modify $schema instead of writing DDL, so it runs on every supported database.
 * Use addPlatformSql() only for what the Schema API cannot express.
 */
final class <className> extends PortableMigration
{
	public function getDescription(): string
	{
		return '';
	}



	public function up(Schema $schema): void
	{
<up>
	}



	public function down(Schema $schema): void
	{
<down>
	}<override>
}
