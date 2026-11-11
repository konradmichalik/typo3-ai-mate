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

use KonradMichalik\Typo3AiMate\DependencyInjection\ServiceTagMapPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Late in TYPE_BEFORE_REMOVING, so tags that other passes add are included.
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new ServiceTagMapPass(), PassConfig::TYPE_BEFORE_REMOVING, -1000);
};
