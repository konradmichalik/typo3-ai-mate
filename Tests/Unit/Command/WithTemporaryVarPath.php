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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};

/**
 * WithTemporaryVarPath.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
trait WithTemporaryVarPath
{
    private string $varPath;

    protected function initVarPath(): void
    {
        $this->varPath = sys_get_temp_dir().'/typo3-ai-mate-'.bin2hex(random_bytes(8));
        mkdir($this->varPath.'/log', 0o777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            false,
            $this->varPath,
            $this->varPath,
            $this->varPath,
            $this->varPath,
            '',
            'UNIX',
        );
    }

    protected function cleanupVarPath(): void
    {
        array_map('unlink', glob($this->varPath.'/log/*') ?: []);
        @rmdir($this->varPath.'/log');
        @rmdir($this->varPath);
    }

    /**
     * @param list<string> $lines
     */
    protected function writeLog(string $infix, array $lines): void
    {
        file_put_contents($this->varPath.'/log/typo3_'.$infix.'.log', implode("\n", [...$lines, '']));
    }
}
