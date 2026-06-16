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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * AbstractJsonCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
abstract class AbstractJsonCommand extends Command
{
    protected function emit(OutputInterface $output, mixed $data, int $exitCode = Command::SUCCESS): int
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $output->writeln(false === $json ? '{"error":"Failed to encode JSON."}' : $json);

        return $exitCode;
    }
}
