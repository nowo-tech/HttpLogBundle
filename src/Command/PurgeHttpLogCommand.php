<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Command;

use InvalidArgumentException;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_sum;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
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
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override retention days (without --channel: every row of every channel)')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Purge only this scope (repeatable): ' . implode(', ', $this->purgeService->scopes()))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count entries without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $days     = $input->getOption('days');
        $days     = is_numeric($days) ? (int) $days : null;
        $channels = $input->getOption('channel');
        $channels = is_array($channels) ? array_values(array_filter($channels, is_string(...))) : [];

        // Per-scope lines when scopes are asked for, or when usage channels have their own retention (and no bare --days).
        if ($channels !== [] || ($days === null && count($this->purgeService->scopes()) > 1)) {
            try {
                $result = $this->purgeService->purgeScopes($channels, $days, $dryRun);
            } catch (InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }

            foreach ($result as $scope => $count) {
                $io->writeln(sprintf($dryRun ? '%s: would purge %d entries' : '%s: purged %d entries', $scope, $count));
            }
            $purged = array_sum($result);
        } else {
            $purged = $this->purgeService->purgeByRetention($days, $dryRun);
        }

        if ($dryRun) {
            $io->success(sprintf('Would purge %d HTTP log entries.', $purged));
        } else {
            $io->success(sprintf('Purged %d HTTP log entries.', $purged));
        }

        return Command::SUCCESS;
    }
}
