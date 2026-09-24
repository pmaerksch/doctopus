<?php

declare(strict_types=1);

use Doctopus\Command\DatabaseCheckCommand;
use Doctopus\Command\MigrationsGenerateCommand;
use Doctopus\Command\MigrationsLintCommand;
use Doctopus\Generator\MigrationGenerator;
use Doctopus\Lint\MigrationLinter;
use Doctopus\Migration\PortableMigrationFactory;
use Doctopus\Platform\PlatformDetector;
use Doctopus\Platform\PlatformPolicy;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;


return static function (ContainerConfigurator $container): void
{
	$services = $container->services();

	$services->set('doctopus.platform_policy', PlatformPolicy::class);
	$services->alias(PlatformPolicy::class, 'doctopus.platform_policy');

	$services->set('doctopus.platform_detector', PlatformDetector::class);
	$services->alias(PlatformDetector::class, 'doctopus.platform_detector');

	$services->set('doctopus.migration_linter', MigrationLinter::class);
	$services->set('doctopus.migration_generator', MigrationGenerator::class);

	$services->set('doctopus.migrations_factory', PortableMigrationFactory::class)
		->decorate('doctrine.migrations.migrations_factory')
		->args([
			service('.inner'),
			service('doctopus.platform_policy'),
		]);

	$services->set('doctopus.command.database_check', DatabaseCheckCommand::class)
		->args([
			service('doctrine'),
			service('doctopus.platform_policy'),
			service('doctopus.platform_detector'),
		])
		->tag('console.command');

	$services->set('doctopus.command.migrations_lint', MigrationsLintCommand::class)
		->args([
			service('doctrine.migrations.dependency_factory'),
			service('doctopus.migration_linter'),
		])
		->tag('console.command');

	$services->set('doctopus.command.migrations_generate', MigrationsGenerateCommand::class)
		->args([
			service('doctrine.migrations.dependency_factory'),
			service('doctopus.migration_generator'),
		])
		->tag('console.command');
};
