<?php

declare(strict_types=1);

namespace Doctopus\Lint;


/**
 * One portability problem found in a migration file.
 */
final class LintIssue
{
	public const ERROR   = 'error';
	public const WARNING = 'warning';



	public function __construct(
		public readonly string $file,
		public readonly int $line,
		public readonly string $severity,
		public readonly string $message,
		public readonly ?string $snippet = null,
	) {}



	public function isError(): bool
	{
		return $this->severity === self::ERROR;
	}
}
