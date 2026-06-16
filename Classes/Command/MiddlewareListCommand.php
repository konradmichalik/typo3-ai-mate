<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

use function is_array;
use function is_string;

/**
 * MiddlewareListCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
#[AsCommand(
    name: 'typo3-ai-mate:middlewares:list',
    description: 'Resolved PSR-15 middleware order of a stack (frontend|backend) as JSON.',
)]
final class MiddlewareListCommand extends Command
{
    public function __construct(private readonly MiddlewareStackResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stack', null, InputOption::VALUE_REQUIRED, 'frontend|backend', 'frontend');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stack = 'backend' === $input->getOption('stack') ? 'backend' : 'frontend';

        try {
            $resolved = $this->resolver->resolve($stack);
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        $middlewares = [];
        foreach ($resolved as $identifier => $value) {
            $middlewares[] = [
                'identifier' => is_string($identifier) ? $identifier : null,
                'target' => is_array($value) ? ($value['target'] ?? null) : $value,
            ];
        }

        return $this->emit($output, ['stack' => $stack, 'middlewares' => $middlewares]);
    }

    /**
     * @param mixed $data
     */
    private function emit(OutputInterface $output, $data, int $exitCode = Command::SUCCESS): int
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $output->writeln(false === $json ? '{"error":"Failed to encode JSON."}' : $json);

        return $exitCode;
    }
}
