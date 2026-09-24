<?php

declare(strict_types=1);

namespace Doctopus\Tests\Fixtures\Migrations;

use Doctopus\Migration\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;


final class Version20260102000000 extends PortableMigration
{
	public function getDescription(): string
	{
		return 'Add customer.active';
	}



	public function up(Schema $schema): void
	{
		$schema->getTable('doctopus_customer')->addColumn('active', Types::BOOLEAN, [ 'default' => true ]);
	}



	public function down(Schema $schema): void
	{
		$schema->getTable('doctopus_customer')->dropColumn('active');
	}
}
