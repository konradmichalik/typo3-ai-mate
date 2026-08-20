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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * AbstractJsonCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class AbstractJsonCommand extends Command
{
    /**
     * Hard gate: every typo3_ai_mate command exposes resolved runtime state and
     * must never run in a Production context. The Mate process already forces
     * TYPO3_CONTEXT=Development when shelling out, but a command invoked directly
     * (deploy user, CI, a package accidentally shipped to production) would
     * otherwise bypass that. Enforcing it here covers all subclasses. Testing
     * (the CI/functional-test context) stays permitted so the suite exercises the
     * real command path.
     */
    final public function run(InputInterface $input, OutputInterface $output): int
    {
        if (Environment::getContext()->isProduction()) {
            return $this->emit(
                $output,
                ['error' => 'typo3_ai_mate is disabled in the Production application context.'],
                Command::FAILURE,
            );
        }

        return parent::run($input, $output);
    }

    final protected function emit(OutputInterface $output, mixed $data, int $exitCode = Command::SUCCESS): int
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $output->writeln(false === $json ? '{"error":"Failed to encode JSON."}' : $json);

        return $exitCode;
    }
}
