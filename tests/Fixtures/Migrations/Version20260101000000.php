<?php

declare(strict_types=1);

namespace Doctopus\Tests\Fixtures\Migrations;

use Doctopus\Migration\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;


final class Version20260101000000 extends PortableMigration
{
	public function getDescription(): string
	{
		return 'Create customer and booking tables';
	}



	public function up(Schema $schema): void
	{
		$customer = $schema->createTable('doctopus_customer');
		$customer->addColumn('id', Types::INTEGER, [ 'autoincrement' => true ]);
		$customer->addColumn('email', Types::STRING, [ 'length' => 180 ]);
		$customer->addColumn('name', Types::STRING, [ 'length' => 255, 'notnull' => false ]);
		$customer->setPrimaryKey([ 'id' ]);
		$customer->addUniqueIndex([ 'email' ], 'uniq_doctopus_customer_email');

		$booking = $schema->createTable('doctopus_booking');
		$booking->addColumn('id', Types::INTEGER, [ 'autoincrement' => true ]);
		$booking->addColumn('customer_id', Types::INTEGER);
		$booking->addColumn('status', Types::STRING, [ 'length' => 20, 'default' => 'open' ]);
		$booking->addColumn('created_at', Types::DATETIME_IMMUTABLE);
		$booking->setPrimaryKey([ 'id' ]);
		$booking->addIndex([ 'customer_id' ], 'idx_doctopus_booking_customer');
		$booking->addForeignKeyConstraint('doctopus_customer', [ 'customer_id' ], [ 'id' ], [ 'onDelete' => 'CASCADE' ], 'fk_doctopus_booking_customer');
	}



	public function down(Schema $schema): void
	{
		$schema->dropTable('doctopus_booking');
		$schema->dropTable('doctopus_customer');
	}
}
