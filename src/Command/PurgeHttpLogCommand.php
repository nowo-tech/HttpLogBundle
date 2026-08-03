<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Command;

use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:http-log:purge',
    description: 'Purge HTTP log entries older than the configured retention period',
)]
final class PurgeHttpLogCommand extends Command
{
    public function __construct(
        private readonly PurgeHttpLogService $purgeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override retention days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count entries without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $days   = $input->getOption('days');
        $days   = is_numeric($days) ? (int) $days : null;

        $purged = $this->purgeService->purgeByRetention($days, $dryRun);

        if ($dryRun) {
            $io->success(sprintf('Would purge %d HTTP log entries.', $purged));
        } else {
            $io->success(sprintf('Purged %d HTTP log entries.', $purged));
        }

        return Command::SUCCESS;
    }
}
