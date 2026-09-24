<?php

declare(strict_types=1);

namespace Doctopus;

use Doctopus\Platform\PlatformPolicy;
use Doctopus\Platform\SupportedPlatform;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


/**
 * doctopus – one set of Doctrine migrations, many databases.
 */
final class DoctopusBundle extends AbstractBundle
{
	public function configure(DefinitionConfigurator $definition): void
	{
		$platformNames = array_column(SupportedPlatform::cases(), 'value');

		$definition->rootNode()
			->children()
				->arrayNode('platforms')
					->info('The database platforms the application supports, as a list ("[mysql, sqlite]") or with minimum versions ("mysql: { min_version: \'8.0\' }").')
					->useAttributeAsKey('name')
					->beforeNormalization()
						->always(static fn (mixed $value): mixed => is_array($value) ? self::normalizePlatforms($value) : $value)
					->end()
					->validate()
						->ifTrue(static fn (array $value): bool => array_diff(array_keys($value), $platformNames) !== [])
						->thenInvalid('Unknown platform in %s, supported are: ' . implode(', ', $platformNames) . '.')
					->end()
					->validate()
						->ifEmpty()
						->thenInvalid('At least one platform must be enabled.')
					->end()
					->defaultValue(self::normalizePlatforms($platformNames))
					->arrayPrototype()
						->children()
							->scalarNode('min_version')->defaultNull()->end()
						->end()
					->end()
				->end()
				->booleanNode('strict')
					->info('Abort migrations on unsupported databases (true) or only print a warning (false).')
					->defaultTrue()
				->end()
				->scalarNode('migration_template')
					->info('Template for doctopus:migrations:generate. Uses the same placeholders as doctrine_migrations.custom_template.')
					->defaultNull()
				->end()
				->arrayNode('lint')
					->addDefaultsIfNotSet()
					->children()
						->scalarNode('since')
							->info('Only lint migrations from this version on, e.g. "Version20260101000000". Useful for existing projects.')
							->defaultNull()
						->end()
						->arrayNode('exclude')
							->info('Migration class names (without namespace) the linter skips.')
							->scalarPrototype()->end()
						->end()
					->end()
				->end()
			->end();
	}



	/**
	 * @param array{platforms: array<string, array{min_version: ?string}>, strict: bool, migration_template: ?string, lint: array{since: ?string, exclude: list<string>}} $config
	 */
	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$container->import('../config/services.php');

		$minVersions = array_map(static fn (array $platform): ?string => $platform['min_version'], $config['platforms']);

		$container->services()
			->get('doctopus.platform_policy')
				->args([ $minVersions, $config['strict'] ]);

		$container->services()
			->get('doctopus.migration_linter')
				->args([ $config['lint']['exclude'], $config['lint']['since'] ]);

		if ( $config['migration_template'] !== null )
		{
			$container->services()
				->get('doctopus.migration_generator')
					->args([ $config['migration_template'] ]);
		}
	}



	/**
	 * Accepts "[mysql, sqlite]", "{mysql: '8.0'}", "{mysql: ~}" and "{mysql: {min_version: '8.0'}}"
	 * and fills in the default minimum version wherever none was given.
	 *
	 * @param array<int|string, mixed> $platforms
	 *
	 * @return array<string|int, mixed>
	 */
	private static function normalizePlatforms(array $platforms): array
	{
		$normalized = [];

		foreach ( $platforms as $key => $value )
		{
			if ( is_int($key) && is_string($value) )
			{
				$key   = $value;
				$value = null;
			}

			if ( $value === null )
			{
				$value = [ 'min_version' => PlatformPolicy::DEFAULT_MIN_VERSIONS[ $key ] ?? null ];
			}
			elseif ( is_string($value) || is_int($value) || is_float($value) )
			{
				$value = [ 'min_version' => (string) $value ];
			}

			$normalized[ strtolower((string) $key) ] = $value;
		}

		return $normalized;
	}
}
