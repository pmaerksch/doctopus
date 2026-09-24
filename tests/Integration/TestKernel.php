<?php

declare(strict_types=1);

namespace Doctopus\Tests\Integration;

use Doctopus\DoctopusBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;


final class TestKernel extends Kernel
{
	use MicroKernelTrait;



	/**
	 * @param array<string, mixed> $doctopusConfig
	 * @param ?string              $migrationsDir  Defaults to the fixture migrations.
	 */
	public function __construct(
		private readonly array $doctopusConfig = [],
		private readonly ?string $migrationsDir = null,
	)
	{
		parent::__construct('test', true);
	}



	public function registerBundles(): iterable
	{
		yield new FrameworkBundle();
		yield new DoctrineBundle();
		yield new DoctrineMigrationsBundle();
		yield new DoctopusBundle();
	}



	/**
	 * Keeps everything the kernel writes (cache, generated config reference) out of the package.
	 */
	public function getProjectDir(): string
	{
		return sys_get_temp_dir() . '/doctopus-tests/' . md5(serialize([ self::VERSION, __DIR__, $this->doctopusConfig, $this->migrationsDir ]));
	}



	public function getCacheDir(): string
	{
		return $this->getProjectDir() . '/cache';
	}



	public function getLogDir(): string
	{
		return sys_get_temp_dir() . '/doctopus-tests/log';
	}



	protected function configureContainer(ContainerConfigurator $container): void
	{
		$container->extension('framework', [
			'test'                  => true,
			'secret'                => 'doctopus',
			'http_method_override'  => false,
			'handle_all_throwables' => true,
			'php_errors'            => [ 'log' => true ],
		]);

		$container->extension('doctrine', [
			'dbal' => [ 'url' => TestDatabase::url(), 'logging' => false ],
		]);

		$container->extension('doctrine_migrations', [
			'migrations_paths' => [
				'Doctopus\Tests\Fixtures\Migrations' => $this->migrationsDir ?? __DIR__ . '/../Fixtures/Migrations',
			],
		]);

		$container->extension('doctopus', $this->doctopusConfig);
	}
}
