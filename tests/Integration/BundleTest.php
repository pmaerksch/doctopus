<?php

declare(strict_types=1);

namespace Doctopus\Tests\Integration;

use Doctopus\Lint\MigrationLinter;
use Doctopus\Migration\PortableMigrationFactory;
use Doctopus\Platform\PlatformPolicy;
use Doctopus\Platform\SupportedPlatform;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;


final class BundleTest extends KernelTestCase
{
	/** @var array<string, mixed> */
	private static array $config = [];

	private static ?string $migrationsDir = null;



	protected static function createKernel(array $options = []): KernelInterface
	{
		return new TestKernel(self::$config, self::$migrationsDir);
	}



	protected function tearDown(): void
	{
		parent::tearDown();
		self::$config        = [];
		self::$migrationsDir = null;
	}



	public function testDefaultConfigurationSupportsAllPlatforms(): void
	{
		$policy = self::getContainer()->get(PlatformPolicy::class);

		self::assertInstanceOf(PlatformPolicy::class, $policy);
		self::assertSame(SupportedPlatform::cases(), $policy->platforms());
		self::assertSame('8.0', $policy->minVersion(SupportedPlatform::MYSQL));
		self::assertTrue($policy->strict);
	}



	public function testPlatformListAndShorthandVersions(): void
	{
		self::$config = [ 'platforms' => [ 'mariadb' => '10.11', 'sqlite' => null ], 'strict' => false ];

		$policy = self::getContainer()->get(PlatformPolicy::class);

		self::assertInstanceOf(PlatformPolicy::class, $policy);
		self::assertSame([ SupportedPlatform::MARIADB, SupportedPlatform::SQLITE ], $policy->platforms());
		self::assertSame('10.11', $policy->minVersion(SupportedPlatform::MARIADB));
		self::assertSame('3.35', $policy->minVersion(SupportedPlatform::SQLITE));
		self::assertFalse($policy->strict);
	}



	public function testDecoratesTheMigrationFactory(): void
	{
		self::assertInstanceOf(PortableMigrationFactory::class, self::getContainer()->get('doctrine.migrations.migrations_factory'));
	}



	public function testDatabaseCheckCommand(): void
	{
		$tester = $this->command('doctopus:database:check');

		self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
		self::assertStringContainsString('compatible', $tester->getDisplay());
	}



	public function testRejectsUnknownPlatformInConfiguration(): void
	{
		self::$config = [ 'platforms' => [ 'postgres_only_would_be_nice' ] ];

		$this->expectExceptionMessageMatches('/Unknown platform/');

		self::bootKernel();
	}



	public function testLintCommandUsesConfiguredMigrationDirectories(): void
	{
		$tester = $this->command('doctopus:migrations:lint');

		self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
		self::assertStringContainsString('Version20260101000000', $tester->getDisplay());
		self::assertStringContainsString('3 migration(s) are portable', $tester->getDisplay());
	}



	public function testLintCommandFailsOnNonPortableMigration(): void
	{
		$file = sys_get_temp_dir() . '/doctopus-tests/Version20269999999999.php';
		(new Filesystem())->dumpFile($file, "<?php\nfinal class Version20269999999999 extends AbstractMigration\n{\n\tpublic function up(\$schema): void\n\t{\n\t\t\$this->addSql('ALTER TABLE a ADD b INT UNSIGNED');\n\t}\n}\n");

		$tester = $this->command('doctopus:migrations:lint');

		self::assertSame(Command::FAILURE, $tester->execute([ 'paths' => [ $file ] ]));
		self::assertStringContainsString('raw DDL detected', $tester->getDisplay());
		self::assertStringContainsString('UNSIGNED', $tester->getDisplay());
	}



	public function testGenerateCommandWritesPortableMigration(): void
	{
		self::$migrationsDir = sys_get_temp_dir() . '/doctopus-tests/generated';
		(new Filesystem())->remove(self::$migrationsDir);

		$tester = $this->command('doctopus:migrations:generate');

		self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

		$files = glob(self::$migrationsDir . '/Version*.php') ?: [];
		self::assertCount(1, $files);

		$code = (string) file_get_contents($files[0]);
		self::assertStringContainsString('namespace Doctopus\Tests\Fixtures\Migrations;', $code);
		self::assertMatchesRegularExpression('/final class Version\d{14} extends PortableMigration/', $code);
		self::assertStringNotContainsString('<up>', $code);
		self::assertSame([], (new MigrationLinter())->lintFile($files[0]));
	}



	private function command(string $name): CommandTester
	{
		$application = new Application(self::bootKernel());

		return new CommandTester($application->find($name));
	}
}
