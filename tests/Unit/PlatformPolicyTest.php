<?php

declare(strict_types=1);

namespace Doctopus\Tests\Unit;

use Doctopus\Platform\DetectedPlatform;
use Doctopus\Platform\PlatformPolicy;
use Doctopus\Platform\SupportedPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;


final class PlatformPolicyTest extends TestCase
{
	public function testAcceptsSupportedPlatformAndVersion(): void
	{
		$policy = new PlatformPolicy();

		self::assertSame([], $policy->violations($this->detected(SupportedPlatform::MARIADB, '11.4.5')));
		self::assertSame([], $policy->violations($this->detected(SupportedPlatform::SQLITE, '3.45.1')));
	}



	public function testRejectsTooOldVersion(): void
	{
		$violations = (new PlatformPolicy())->violations($this->detected(SupportedPlatform::MYSQL, '5.7.44'));

		self::assertCount(1, $violations);
		self::assertStringContainsString('MySQL 5.7.44 is too old, at least 8.0', $violations[0]);
	}



	public function testRejectsDisabledPlatform(): void
	{
		$policy     = new PlatformPolicy([ 'mysql' => null, 'mariadb' => '10.6' ]);
		$violations = $policy->violations($this->detected(SupportedPlatform::SQLITE, '3.45.1'));

		self::assertCount(1, $violations);
		self::assertStringContainsString('SQLite is not enabled', $violations[0]);
		self::assertSame('MySQL, MariaDB >= 10.6', $policy->describePlatforms());
	}



	public function testRejectsUnsupportedPlatform(): void
	{
		$detected = new DetectedPlatform(null, null, PostgreSQLPlatform::class, '16.4');

		self::assertStringContainsString('Unsupported database platform', (new PlatformPolicy())->violations($detected)[0]);
	}



	public function testWarnsAboutMariaDbServerWithMySqlServerVersion(): void
	{
		$detected = new DetectedPlatform(SupportedPlatform::MARIADB, SupportedPlatform::MYSQL, MySQLPlatform::class, '11.4.5');
		$policy   = new PlatformPolicy();

		self::assertSame([], $policy->violations($detected));
		self::assertStringContainsString('serverVersion', $policy->warnings($detected)[0]);
	}



	public function testRejectsUnknownPlatformNames(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new PlatformPolicy([ 'postgresql' => '16' ]);
	}



	private function detected(SupportedPlatform $platform, string $version): DetectedPlatform
	{
		return new DetectedPlatform($platform, $platform, 'irrelevant', $version);
	}
}
