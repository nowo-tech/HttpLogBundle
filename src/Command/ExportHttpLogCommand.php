<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Command;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function in_array;
use function is_string;
use function sprintf;

#[AsCommand(
    name: 'nowo:http-log:export',
    description: 'Export HTTP log entries to CSV or JSON',
)]
final class ExportHttpLogCommand extends Command
{
    public function __construct(
        private readonly ExportHttpLogService $exportService,
        private readonly ClockInterface $clock,
        /** @var list<string> Allowed export formats (export.formats). */
        private readonly array $exportFormats = ['csv', 'json'],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format (csv|json, limited to nowo_http_log.export.formats)', ExportFormat::Csv->value)
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file path')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Only export entries from the last N days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $formatValue = (string) $input->getOption('format');
        $format      = ExportFormat::tryFrom($formatValue) ?? ExportFormat::Csv;
        if (!in_array($format->value, $this->exportFormats, true)) {
            $io->error(sprintf('Export format "%s" is not enabled. Allowed: %s (nowo_http_log.export.formats).', $format->value, implode(', ', $this->exportFormats)));

            return Command::INVALID;
        }

        $outputPath = $input->getOption('output');
        if (!is_string($outputPath) || $outputPath === '') {
            $io->error('The --output option is required.');

            return Command::FAILURE;
        }

        $criteria = [];
        $days     = $input->getOption('days');
        if (is_numeric($days)) {
            $criteria['createdFrom'] = $this->clock->now()->modify(sprintf('-%d days', (int) $days));
        }

        $rows = $this->exportService->exportToFile($criteria, $format, $outputPath);
        $io->success(sprintf('Exported %d HTTP log entries to %s.', $rows, $outputPath));

        return Command::SUCCESS;
    }
}
