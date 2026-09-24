<?php

declare(strict_types=1);

namespace Doctopus\Platform;


/**
 * The set of platforms (and minimum versions) an application claims to support.
 */
final class PlatformPolicy
{
	public const DEFAULT_MIN_VERSIONS = [
		'mysql'   => '8.0',
		'mariadb' => '10.6',
		'sqlite'  => '3.35',
	];


	/** @var array<string, ?string> platform value => minimum version */
	private readonly array $minVersions;



	/**
	 * @param array<string, ?string> $minVersions Platform value ("mysql", "mariadb", "sqlite") => minimum version or null.
	 * @param bool                   $strict      Abort migrations on violations (true) or only warn (false).
	 */
	public function __construct(array $minVersions = self::DEFAULT_MIN_VERSIONS, public readonly bool $strict = true)
	{
		foreach ( array_keys($minVersions) as $name )
		{
			if ( SupportedPlatform::tryFrom($name) === null )
			{
				throw new \InvalidArgumentException(sprintf('Unknown platform "%s", expected one of: %s.', $name, implode(', ', array_column(SupportedPlatform::cases(), 'value'))));
			}
		}

		$this->minVersions = $minVersions;
	}



	/**
	 * @return list<SupportedPlatform>
	 */
	public function platforms(): array
	{
		return array_map(SupportedPlatform::from(...), array_keys($this->minVersions));
	}



	public function supports(SupportedPlatform $platform): bool
	{
		return array_key_exists($platform->value, $this->minVersions);
	}



	public function minVersion(SupportedPlatform $platform): ?string
	{
		return $this->minVersions[ $platform->value ] ?? null;
	}



	/**
	 * Lists everything that keeps the detected database from being compatible. Empty means compatible.
	 *
	 * @return list<string>
	 */
	public function violations(DetectedPlatform $detected): array
	{
		if ( $detected->platform === null )
		{
			return [ sprintf('Unsupported database platform %s. Supported: %s.', $detected->platformClass, $this->describePlatforms()) ];
		}

		$platform = $detected->platform;

		if ( !$this->supports($platform) )
		{
			return [ sprintf('%s is not enabled for this application. Supported: %s.', $platform->label(), $this->describePlatforms()) ];
		}

		$violations = [];
		$minVersion = $this->minVersion($platform);

		if ( $minVersion !== null && $detected->version !== null && version_compare($detected->version, $minVersion, '<') )
		{
			$violations[] = sprintf('%s %s is too old, at least %s is required.', $platform->label(), $detected->version, $minVersion);
		}

		return $violations;
	}



	/**
	 * Lists things that are probably wrong but do not make the database incompatible.
	 *
	 * @return list<string>
	 */
	public function warnings(DetectedPlatform $detected): array
	{
		if ( !$detected->hasPlatformMismatch() )
		{
			return [];
		}

		return [
			sprintf(
				'The server is %s but DBAL generates SQL for %s. Check the "serverVersion" of your DATABASE_URL.',
				$detected->platform?->label(),
				$detected->dbalPlatform?->label(),
			),
		];
	}



	public function describePlatforms(): string
	{
		$parts = [];

		foreach ( $this->platforms() as $platform )
		{
			$minVersion = $this->minVersion($platform);
			$parts[]    = $minVersion !== null ? sprintf('%s >= %s', $platform->label(), $minVersion) : $platform->label();
		}

		return implode(', ', $parts);
	}
}
