<?php

declare(strict_types=1);

namespace Doctopus\Lint;


/**
 * Scans migration source files for SQL that ties them to one database platform.
 *
 * It is a heuristic, not a SQL parser: its job is to catch accidental coupling
 * (DDL in addSql(), MySQL-only functions, ...) before it reaches production.
 *
 * SQL passed to addPlatformSql() is deliberately platform-specific and not checked.
 * A line (or the line above it) containing "@doctopus-ignore" suppresses its issues.
 */
final class MigrationLinter
{
	public const IGNORE_MARKER = '@doctopus-ignore';

	/** Methods whose first argument is raw SQL. */
	private const SQL_METHODS = [ 'addsql', 'executestatement', 'executequery' ];

	private const DDL_PATTERN = '/\b(?:CREATE|ALTER|DROP|RENAME)\s+(?:TEMPORARY\s+)?(?:UNIQUE\s+)?(?:TABLE|INDEX|VIEW|TRIGGER|SEQUENCE)\b|\bTRUNCATE\b/i';

	/** Label => pattern for constructs that only exist in (or behave differently on) some of the supported platforms. */
	public const DIALECT_PATTERNS = [
		'AUTO_INCREMENT / AUTOINCREMENT'  => '/\bAUTO_?INCREMENT\b/i',
		'ENGINE='                         => '/\bENGINE\s*=/i',
		'COLLATE'                         => '/\bCOLLATE\b/i',
		'CHARACTER SET'                   => '/\b(?:CHARACTER\s+SET|CHARSET)\b/i',
		'UNSIGNED'                        => '/\bUNSIGNED\b/i',
		'ENUM()'                          => '/\bENUM\s*\(/i',
		'ON DUPLICATE KEY UPDATE'         => '/\bON\s+DUPLICATE\s+KEY\b/i',
		'INSERT IGNORE'                   => '/\bINSERT\s+IGNORE\b/i',
		'INSERT OR IGNORE / OR REPLACE'   => '/\bINSERT\s+OR\s+(?:IGNORE|REPLACE)\b/i',
		'REPLACE INTO'                    => '/\bREPLACE\s+INTO\b/i',
		'MATCH ... AGAINST'               => '/\bAGAINST\s*\(/i',
		'JSON_EXTRACT()'                  => '/\bJSON_EXTRACT\s*\(/i',
		'FIND_IN_SET()'                   => '/\bFIND_IN_SET\s*\(/i',
		'GROUP_CONCAT()'                  => '/\bGROUP_CONCAT\s*\(/i',
		'DATE_FORMAT()'                   => '/\bDATE_FORMAT\s*\(/i',
		'STR_TO_DATE()'                   => '/\bSTR_TO_DATE\s*\(/i',
		'DATE_ADD() / DATE_SUB()'         => '/\bDATE_(?:ADD|SUB)\s*\(/i',
		'INTERVAL'                        => '/\bINTERVAL\s+[\'"\d]/i',
		'NOW()'                           => '/\bNOW\s*\(\s*\)/i',
		'CURDATE() / CURTIME()'           => '/\bCUR(?:DATE|TIME)\s*\(/i',
		'UNIX_TIMESTAMP()'                => '/\bUNIX_TIMESTAMP\s*\(/i',
		'LAST_INSERT_ID()'                => '/\bLAST_INSERT_ID\s*\(/i',
		'IF()'                            => '/(?<![\w.])IF\s*\(/i',
		'strftime() / datetime()'         => '/\b(?:strftime|datetime|julianday)\s*\(/i',
		'PRAGMA'                          => '/\bPRAGMA\b/i',
	];



	/**
	 * @param list<string> $exclude Migration class names (without namespace) to skip.
	 * @param ?string      $since   Skip migrations whose class name sorts before this one (e.g. "Version20260101000000").
	 */
	public function __construct(
		private readonly array $exclude = [],
		private readonly ?string $since = null,
	) {}



	/**
	 * @param iterable<string> $paths Files or directories.
	 *
	 * @return array<string, list<LintIssue>> file => issues, for every linted file (empty list = clean)
	 */
	public function lintPaths(iterable $paths): array
	{
		$results = [];

		foreach ( $paths as $path )
		{
			foreach ( $this->collectFiles($path) as $file )
			{
				if ( $this->isSkipped($file) )
				{
					continue;
				}

				$results[ $file ] = $this->lintFile($file);
			}
		}

		ksort($results);

		return $results;
	}



	/**
	 * @return list<LintIssue>
	 */
	public function lintFile(string $file): array
	{
		$source = @file_get_contents($file);

		if ( $source === false )
		{
			return [ new LintIssue($file, 0, LintIssue::ERROR, 'File could not be read.') ];
		}

		return $this->lintSource($source, $file);
	}



	/**
	 * @return list<LintIssue>
	 */
	public function lintSource(string $source, string $file = 'source'): array
	{
		$tokens = token_get_all($source);
		$lines  = preg_split('/\R/', $source) ?: [];
		$issues = [];
		$count  = count($tokens);

		for ( $i = 0; $i < $count; $i++ )
		{
			$token = $tokens[ $i ];

			if ( !is_array($token) )
			{
				continue;
			}

			if ( $token[0] === T_EXTENDS )
			{
				$parent = $this->nextName($tokens, $i);

				if ( $parent !== null && preg_match('/(^|\\\\)AbstractMigration$/', $parent) === 1 )
				{
					$issues[] = new LintIssue($file, $token[2], LintIssue::ERROR, 'Migration extends AbstractMigration instead of Doctopus\Migration\PortableMigration.');
				}

				continue;
			}

			if ( $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::SQL_METHODS, true) || !$this->isMethodCall($tokens, $i) )
			{
				continue;
			}

			$argument = $this->firstArgument($tokens, $i);

			if ( $argument === null )
			{
				continue;
			}

			$line = $token[2];

			foreach ( $this->checkSql($argument['sql'], $argument['dynamic']) as [ $severity, $message ] )
			{
				$issues[] = new LintIssue($file, $line, $severity, sprintf('%s(): %s', $token[1], $message), $this->snippet($argument['sql']));
			}
		}

		return array_values(array_filter($issues, fn (LintIssue $issue): bool => !$this->isIgnored($lines, $issue->line)));
	}



	/**
	 * @return list<array{string, string}> severity, message
	 */
	private function checkSql(string $sql, bool $dynamic): array
	{
		$found = [];

		if ( trim($sql) === '' )
		{
			return $dynamic ? [ [ LintIssue::WARNING, 'SQL is built dynamically and cannot be checked; prefer the Schema API or addPlatformSql().' ] ] : [];
		}

		if ( preg_match(self::DDL_PATTERN, $sql) === 1 )
		{
			$found[] = [ LintIssue::ERROR, 'raw DDL detected; modify $schema instead or use addPlatformSql().' ];
		}

		foreach ( self::DIALECT_PATTERNS as $label => $pattern )
		{
			if ( preg_match($pattern, $sql) === 1 )
			{
				$found[] = [ LintIssue::ERROR, sprintf('platform-specific SQL detected: %s', $label) ];
			}
		}

		if ( $found === [] && $dynamic )
		{
			$found[] = [ LintIssue::WARNING, 'SQL is partly built dynamically and cannot be fully checked.' ];
		}

		return $found;
	}



	/**
	 * Collects the string literals of the first call argument after $tokens[$i] and whether anything non-literal is mixed in.
	 *
	 * @param array<int, array{int, string, int}|string> $tokens
	 *
	 * @return ?array{sql: string, dynamic: bool}
	 */
	private function firstArgument(array $tokens, int $i): ?array
	{
		$count = count($tokens);
		$i     = $this->skipWhitespace($tokens, $i + 1);

		if ( $i >= $count || $tokens[ $i ] !== '(' )
		{
			return null;
		}

		$depth   = 0;
		$sql     = '';
		$dynamic = false;

		for ( ; $i < $count; $i++ )
		{
			$token = $tokens[ $i ];
			$text  = is_array($token) ? $token[1] : $token;

			if ( in_array($text, [ '(', '[', '{' ], true) || ( is_array($token) && in_array($token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true) ) )
			{
				$depth++;

				if ( $depth === 1 )
				{
					continue;
				}
			}
			elseif ( in_array($text, [ ')', ']', '}' ], true) )
			{
				$depth--;

				if ( $depth === 0 )
				{
					break;
				}
			}
			elseif ( $text === ',' && $depth === 1 )
			{
				break;
			}

			if ( !is_array($token) )
			{
				if ( !in_array($token, [ '.', '"', '(', ')' ], true) )
				{
					$dynamic = true;
				}

				continue;
			}

			switch ( $token[0] )
			{
				case T_CONSTANT_ENCAPSED_STRING:
					$sql .= $this->unquote($token[1]) . ' ';
					break;

				case T_ENCAPSED_AND_WHITESPACE:
					$sql .= $token[1];
					break;

				case T_WHITESPACE:
				case T_COMMENT:
				case T_DOC_COMMENT:
				case T_START_HEREDOC:
				case T_END_HEREDOC:
					break;

				default:
					$dynamic = true;
			}
		}

		return [ 'sql' => $sql, 'dynamic' => $dynamic ];
	}



	private function unquote(string $literal): string
	{
		$quote = $literal[0];
		$body  = substr($literal, 1, -1);

		return $quote === "'" ? str_replace([ "\\'", '\\\\' ], [ "'", '\\' ], $body) : stripcslashes($body);
	}



	/**
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private function isMethodCall(array $tokens, int $i): bool
	{
		for ( $j = $i - 1; $j >= 0; $j-- )
		{
			if ( is_array($tokens[ $j ]) && $tokens[ $j ][0] === T_WHITESPACE )
			{
				continue;
			}

			return is_array($tokens[ $j ]) && in_array($tokens[ $j ][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ], true);
		}

		return false;
	}



	/**
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private function nextName(array $tokens, int $i): ?string
	{
		$i = $this->skipWhitespace($tokens, $i + 1);

		if ( !isset($tokens[ $i ]) || !is_array($tokens[ $i ]) )
		{
			return null;
		}

		return in_array($tokens[ $i ][0], [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true) ? $tokens[ $i ][1] : null;
	}



	/**
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private function skipWhitespace(array $tokens, int $i): int
	{
		$count = count($tokens);

		while ( $i < $count && is_array($tokens[ $i ]) && in_array($tokens[ $i ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true) )
		{
			$i++;
		}

		return $i;
	}



	/**
	 * @param list<string> $lines
	 */
	private function isIgnored(array $lines, int $line): bool
	{
		foreach ( [ $line - 1, $line - 2 ] as $index )
		{
			if ( isset($lines[ $index ]) && str_contains($lines[ $index ], self::IGNORE_MARKER) )
			{
				return true;
			}
		}

		return false;
	}



	private function snippet(string $sql): ?string
	{
		$sql = trim((string) preg_replace('/\s+/', ' ', $sql));

		if ( $sql === '' )
		{
			return null;
		}

		return mb_strlen($sql) > 120 ? mb_substr($sql, 0, 117) . '...' : $sql;
	}



	/**
	 * @return list<string>
	 */
	private function collectFiles(string $path): array
	{
		if ( is_file($path) )
		{
			return [ $path ];
		}

		if ( !is_dir($path) )
		{
			return [];
		}

		$files    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

		foreach ( $iterator as $file )
		{
			if ( $file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php' )
			{
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}



	private function isSkipped(string $file): bool
	{
		$class = basename($file, '.php');

		if ( in_array($class, $this->exclude, true) )
		{
			return true;
		}

		return $this->since !== null && strcmp($class, $this->since) < 0;
	}
}
