<?php

declare(strict_types=1);

namespace Doctopus\Tests\Unit;

use Doctopus\Lint\LintIssue;
use Doctopus\Lint\MigrationLinter;
use PHPUnit\Framework\TestCase;


final class MigrationLinterTest extends TestCase
{
	public function testPortableMigrationIsClean(): void
	{
		$issues = $this->lint(<<<'PHP'
			final class Version1 extends PortableMigration
			{
				public function up(Schema $schema): void
				{
					$schema->getTable('customer')->addColumn('active', Types::BOOLEAN, [ 'default' => true ]);
					$this->addSql('UPDATE customer SET active = ? WHERE id = ?', [ true, 1 ]);
					$this->addPlatformSql(
						mysql: 'ALTER TABLE customer ENGINE=InnoDB',
						sqlite: '',
					);
				}
			}
			PHP);

		self::assertSame([], $issues);
	}



	public function testFlagsGeneratedDoctrineMigration(): void
	{
		$issues = $this->lint(<<<'PHP'
			use Doctrine\Migrations\AbstractMigration;

			final class Version2 extends AbstractMigration
			{
				public function up(Schema $schema): void
				{
					$this->addSql('CREATE TABLE customer (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
				}
			}
			PHP);

		$messages = array_map(static fn (LintIssue $issue): string => $issue->message, $issues);

		self::assertContains('Migration extends AbstractMigration instead of Doctopus\Migration\PortableMigration.', $messages);
		self::assertContains('addSql(): raw DDL detected; modify $schema instead or use addPlatformSql().', $messages);
		self::assertContains('addSql(): platform-specific SQL detected: AUTO_INCREMENT / AUTOINCREMENT', $messages);
		self::assertContains('addSql(): platform-specific SQL detected: ENGINE=', $messages);
		self::assertContains('addSql(): platform-specific SQL detected: COLLATE', $messages);
		self::assertContains('addSql(): platform-specific SQL detected: CHARACTER SET', $messages);
		self::assertSame(8, $issues[1]->line);
	}



	public function testFlagsDialectFunctionsAcrossConcatenationAndHeredoc(): void
	{
		$issues = $this->lint(<<<'PHP'
			final class Version3 extends PortableMigration
			{
				public function up(Schema $schema): void
				{
					$this->addSql('INSERT INTO report (month) ' . "SELECT DATE_FORMAT(created, '%Y-%m') FROM booking");
					$this->connection->executeStatement(<<<SQL
						UPDATE booking SET tags = (SELECT GROUP_CONCAT(name) FROM tag)
						SQL);
				}
			}
			PHP);

		self::assertCount(2, $issues);
		self::assertStringContainsString('DATE_FORMAT()', $issues[0]->message);
		self::assertStringContainsString('executeStatement(): platform-specific SQL detected: GROUP_CONCAT()', $issues[1]->message);
	}



	public function testWarnsAboutDynamicSql(): void
	{
		$issues = $this->lint(<<<'PHP'
			final class Version4 extends PortableMigration
			{
				public function up(Schema $schema): void
				{
					$this->addSql($sql);
					$this->addSql(sprintf('UPDATE %s SET a = 1', $table));
				}
			}
			PHP);

		self::assertCount(2, $issues);
		self::assertSame(LintIssue::WARNING, $issues[0]->severity);
		self::assertSame(LintIssue::WARNING, $issues[1]->severity);
	}



	public function testIgnoreMarkerSuppressesIssues(): void
	{
		$issues = $this->lint(<<<'PHP'
			final class Version5 extends PortableMigration
			{
				public function up(Schema $schema): void
				{
					// @doctopus-ignore works on every platform we ship
					$this->addSql('UPDATE booking SET updated = NOW()');
					$this->addSql('UPDATE booking SET paid = IF(total > 0, 1, 0)'); // @doctopus-ignore
				}
			}
			PHP);

		self::assertSame([], $issues);
	}



	public function testLintsDirectoriesWithSinceAndExclude(): void
	{
		$directory = __DIR__ . '/../Fixtures/Migrations';

		$all     = (new MigrationLinter())->lintPaths([ $directory ]);
		$since   = (new MigrationLinter(since: 'Version20260102000000'))->lintPaths([ $directory ]);
		$exclude = (new MigrationLinter(exclude: [ 'Version20260101000000' ]))->lintPaths([ $directory ]);

		self::assertCount(3, $all);
		self::assertCount(2, $since);
		self::assertCount(2, $exclude);

		foreach ( $all as $issues )
		{
			self::assertSame([], $issues);
		}
	}



	/**
	 * @return list<LintIssue>
	 */
	private function lint(string $code): array
	{
		return (new MigrationLinter())->lintSource("<?php\n" . $code);
	}
}
