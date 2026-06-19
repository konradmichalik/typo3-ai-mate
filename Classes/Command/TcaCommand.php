<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

use function is_array;
use function is_string;
use function sprintf;

/**
 * TcaCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:tca:dump',
    description: 'Resolved TCA of a table (trimmed) or the list of all table names as JSON.',
)]
final class TcaCommand extends AbstractJsonCommand
{
    /**
     * Reduce a full TCA table definition to the fields that matter for an
     * assistant reasoning about content modelling.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>}
     */
    public function extractTable(array $definition): array
    {
        /** @var array<string, mixed> $ctrl */
        $ctrl = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        /** @var array<string, mixed> $columns */
        $columns = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];

        $trimmedColumns = [];
        foreach ($columns as $field => $column) {
            if (!is_array($column)) {
                continue;
            }
            /** @var array<string, mixed> $config */
            $config = is_array($column['config'] ?? null) ? $column['config'] : [];

            $trimmedColumns[$field] = array_filter([
                'label' => $column['label'] ?? null,
                'type' => $config['type'] ?? null,
                'renderType' => $config['renderType'] ?? null,
                'foreign_table' => $config['foreign_table'] ?? null,
                'eval' => $config['eval'] ?? null,
                'displayCond' => $column['displayCond'] ?? null,
            ], static fn (mixed $value): bool => null !== $value);
        }

        return [
            'ctrl' => array_filter([
                'title' => $ctrl['title'] ?? null,
                'label' => $ctrl['label'] ?? null,
                'type' => $ctrl['type'] ?? null,
                'sortby' => $ctrl['sortby'] ?? null,
            ], static fn (mixed $value): bool => null !== $value),
            'columns' => $trimmedColumns,
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::OPTIONAL, 'TCA table name, e.g. tt_content')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List all TCA table names instead of dumping a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<string, mixed> $tca */
        $tca = $GLOBALS['TCA'] ?? [];
        $table = $input->getArgument('table');

        if (true === $input->getOption('list') || !is_string($table) || '' === $table) {
            $names = array_keys($tca);
            sort($names);

            return $this->emit($output, $names);
        }

        if (!isset($tca[$table]) || !is_array($tca[$table])) {
            return $this->emit($output, ['error' => sprintf('Unknown TCA table "%s".', $table)], Command::FAILURE);
        }

        /** @var array<string, mixed> $definition */
        $definition = $tca[$table];

        return $this->emit($output, $this->extractTable($definition));
    }
}
