<?php

declare(strict_types=1);

namespace Doctopus\Command;

use Doctopus\Platform\PlatformDetector;
use Doctopus\Platform\PlatformPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;


#[AsCommand(
	name: 'doctopus:database:check',
	description: 'Checks that the configured database is one of the platforms (and versions) the application supports',
)]
final class DatabaseCheckCommand extends Command
{
	public function __construct(
		private readonly ConnectionRegistry $connections,
		private readonly PlatformPolicy $policy,
		private readonly PlatformDetector $detector = new PlatformDetector(),
	)
	{
		parent::__construct();
	}



	protected function configure(): void
	{
		$this->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'The Doctrine connection to check (default connection if omitted)');
	}



	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io   = new SymfonyStyle($input, $output);
		$name = $input->getOption('connection');

		$io->title('Database compatibility');

		try
		{
			$connection = $this->connections->getConnection(is_string($name) ? $name : null);
			assert($connection instanceof Connection);
			$detected   = $this->detector->detect($connection);
		}
		catch ( Throwable $e )
		{
			$io->error(sprintf('Could not connect to the database: %s', $e->getMessage()));

			return Command::FAILURE;
		}

		$io->definitionList(
			[ 'Platform' => $detected->label() ],
			[ 'Version' => $detected->rawVersion ?? 'unknown' ],
			[ 'DBAL platform' => $detected->platformClass ],
			[ 'Supported' => $this->policy->describePlatforms() ],
		);

		$violations = $this->policy->violations($detected);
		$warnings   = $this->policy->warnings($detected);

		foreach ( $warnings as $warning )
		{
			$io->warning($warning);
		}

		if ( $violations !== [] )
		{
			$io->error($violations);

			return Command::FAILURE;
		}

		if ( $detected->version === null )
		{
			$io->warning('The server version could not be determined, so the minimum version was not checked.');
		}

		$io->success('Application database configuration is compatible.');

		return Command::SUCCESS;
	}
}
