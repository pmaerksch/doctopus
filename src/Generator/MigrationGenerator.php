<?php

declare(strict_types=1);

namespace Doctopus\Generator;


/**
 * Writes new, empty migration classes that extend PortableMigration.
 */
final class MigrationGenerator
{
	public const DEFAULT_TEMPLATE = __DIR__ . '/../../templates/migration.php.tpl';



	public function __construct(
		private readonly string $templateFile = self::DEFAULT_TEMPLATE,
	) {}



	public function render(string $namespace, string $className): string
	{
		$template = @file_get_contents($this->templateFile);

		if ( $template === false )
		{
			throw new \RuntimeException(sprintf('Migration template "%s" could not be read.', $this->templateFile));
		}

		return strtr($template, [
			'<namespace>' => $namespace,
			'<className>' => $className,
			'<up>'        => '',
			'<down>'      => '',
			'<override>'  => '',
		]);
	}



	/**
	 * @return string The path of the written file.
	 */
	public function generate(string $namespace, string $directory, string $className): string
	{
		if ( !is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory) )
		{
			throw new \RuntimeException(sprintf('Migration directory "%s" could not be created.', $directory));
		}

		$path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $className . '.php';

		if ( file_exists($path) )
		{
			throw new \RuntimeException(sprintf('Migration "%s" already exists.', $path));
		}

		file_put_contents($path, $this->render($namespace, $className));

		return $path;
	}



	public static function versionClassName(?\DateTimeInterface $now = null): string
	{
		return 'Version' . ( $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) )->format('YmdHis');
	}
}
