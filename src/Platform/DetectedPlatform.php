<?php

declare(strict_types=1);

namespace Doctopus\Platform;


/**
 * What doctopus found out about the database behind a connection.
 */
final class DetectedPlatform
{
	/**
	 * @param ?SupportedPlatform $platform      The platform the server really is (null if unsupported).
	 * @param ?SupportedPlatform $dbalPlatform  The platform DBAL generates SQL for (null if unsupported).
	 * @param string             $platformClass The DBAL platform class in use.
	 * @param ?string            $version       Normalized server version, e.g. "10.6.12" (null if unknown).
	 * @param ?string            $rawVersion    The version string exactly as the server reported it.
	 */
	public function __construct(
		public readonly ?SupportedPlatform $platform,
		public readonly ?SupportedPlatform $dbalPlatform,
		public readonly string $platformClass,
		public readonly ?string $version,
		public readonly ?string $rawVersion = null,
	) {}



	public function isSupported(): bool
	{
		return $this->platform !== null;
	}



	/**
	 * True when DBAL renders SQL for a different platform than the server actually is,
	 * typically a MariaDB server configured with a MySQL "serverVersion" (or vice versa).
	 */
	public function hasPlatformMismatch(): bool
	{
		return $this->platform !== null && $this->dbalPlatform !== null && $this->platform !== $this->dbalPlatform;
	}



	public function label(): string
	{
		return $this->platform?->label() ?? $this->platformClass;
	}
}
