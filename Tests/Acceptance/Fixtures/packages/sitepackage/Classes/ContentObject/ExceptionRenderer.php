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

namespace Test\Sitepackage\ContentObject;

use RuntimeException;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;

/**
 * ExceptionRenderer.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class ExceptionRenderer
{
    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf): string
    {
        throw new RuntimeException('AI Mate demo: intentional exception for the typo3-logs-* tools.', 1718000201);
    }
}
