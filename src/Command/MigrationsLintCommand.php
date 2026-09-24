<?php

declare(strict_types=1);

namespace Doctopus\Command;

use Doctopus\Lint\LintIssue;
use Doctopus\Lint\MigrationLinter;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


#[AsCommand(
	name: 'doctopus:migrations:lint',
	description: 'Checks migrations for SQL that is not portable across the supported databases',
)]
final class MigrationsLintCommand extends Command
{
	public function __construct(
		private readonly DependencyFactory $dependencyFactory,
		private readonly MigrationLinter $linter,
	)
	{
		parent::__construct();
	}



	protected function configure(): void
	{
		$this
			->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to lint (default: the configured migration directories)')
			->addOption('fail-on-warning', null, InputOption::VALUE_NONE, 'Also fail when only warnings were found')
			->setHelp(<<<'HELP'
				Scans migration classes for accidental database coupling: raw DDL in <info>addSql()</info>,
				MySQL- or SQLite-only syntax and functions, and migrations that do not extend
				<info>Doctopus\Migration\PortableMigration</info>.

				SQL given to <info>addPlatformSql()</info> is platform-specific on purpose and not checked.
				Put <comment>// @doctopus-ignore</comment> on or above a line to suppress its issues.
				HELP);
	}



	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io    = new SymfonyStyle($input, $output);
		$paths = $input->getArgument('paths');

		if ( $paths === [] )
		{
			$paths = array_values($this->dependencyFactory->getConfiguration()->getMigrationDirectories());
		}

		$io->title('Checking migrations');

		$results  = $this->linter->lintPaths($paths);
		$errors   = 0;
		$warnings = 0;

		foreach ( $results as $file => $issues )
		{
			$name = basename($file, '.php');

			if ( $issues === [] )
			{
				$io->writeln(sprintf(' <info>✓</info> %s', $name));
				continue;
			}

			$io->writeln(sprintf(' <error>✗</error> %s', $name));

			foreach ( $issues as $issue )
			{
				if ( $issue->isError() )
				{
					$errors++;
				}
				else
				{
					$warnings++;
				}

				$io->writeln(sprintf(
					'     <%1$s>%2$s</%1$s> line %3$d: %4$s',
					$issue->isError() ? 'fg=red' : 'fg=yellow',
					$issue->severity,
					$issue->line,
					$issue->message,
				));

				if ( $issue->snippet !== null )
				{
					$io->writeln(sprintf('       <comment>%s</comment>', $issue->snippet));
				}
			}
		}

		$io->newLine();

		if ( $results === [] )
		{
			$io->warning('No migrations found.');

			return Command::SUCCESS;
		}

		if ( $errors > 0 || ( $warnings > 0 && $input->getOption('fail-on-warning') ) )
		{
			$io->error(sprintf('%d portability error(s), %d warning(s) in %d migration(s).', $errors, $warnings, count($results)));

			return Command::FAILURE;
		}

		if ( $warnings > 0 )
		{
			$io->warning(sprintf('%d warning(s) in %d migration(s).', $warnings, count($results)));

			return Command::SUCCESS;
		}

		$io->success(sprintf('%d migration(s) are portable.', count($results)));

		return Command::SUCCESS;
	}
}
