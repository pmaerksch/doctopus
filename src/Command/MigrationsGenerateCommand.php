<?php

declare(strict_types=1);

namespace Doctopus\Command;

use Doctopus\Generator\MigrationGenerator;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


#[AsCommand(
	name: 'doctopus:migrations:generate',
	description: 'Generates a blank portable migration class',
)]
final class MigrationsGenerateCommand extends Command
{
	public function __construct(
		private readonly DependencyFactory $dependencyFactory,
		private readonly MigrationGenerator $generator,
	)
	{
		parent::__construct();
	}



	protected function configure(): void
	{
		$this->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'The migration namespace to use (required when several are configured)');
	}



	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io          = new SymfonyStyle($input, $output);
		$directories = $this->dependencyFactory->getConfiguration()->getMigrationDirectories();
		$namespace   = $input->getOption('namespace');

		if ( $directories === [] )
		{
			$io->error('No migration directories are configured (doctrine_migrations.migrations_paths).');

			return Command::FAILURE;
		}

		if ( !is_string($namespace) )
		{
			if ( count($directories) > 1 )
			{
				$namespace = $io->choice('Which migration namespace should be used?', array_keys($directories));
			}
			else
			{
				$namespace = array_key_first($directories);
			}
		}

		if ( !isset($directories[ $namespace ]) )
		{
			$io->error(sprintf('Unknown migration namespace "%s". Configured: %s', $namespace, implode(', ', array_keys($directories))));

			return Command::FAILURE;
		}

		$path = $this->generator->generate($namespace, $directories[ $namespace ], MigrationGenerator::versionClassName());

		$io->success(sprintf('Generated new portable migration class to "%s".', $path));
		$io->text([
			'Modify <info>$schema</info> in up() and down(); avoid DDL in addSql().',
			'Run <info>doctopus:migrations:lint</info> before committing.',
		]);

		return Command::SUCCESS;
	}
}
