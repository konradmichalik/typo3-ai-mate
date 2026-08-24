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

use Composer\Autoload;
use ShipMonk\ComposerDependencyAnalyser;

$rootPath = dirname(__DIR__, 2);

/** @var Autoload\ClassLoader $loader */
$loader = require $rootPath.'/vendor/autoload.php';
$loader->register();

$configuration = new ComposerDependencyAnalyser\Config\Configuration();
$configuration
    ->addPathToScan($rootPath.'/Configuration', false)
    ->addPathsToExclude([
        $rootPath.'/Tests/CGL',
        // Test fixtures deliberately reference core classes removed in old TYPO3
        // versions so the extension scanner has something to flag — not real code.
        $rootPath.'/Tests/Functional/Fixtures',
    ])
    // helgesverre/toon is used at runtime by ai-mate's ResponseEncoder to encode tool
    // responses as TOON, but this package never references its symbols directly — so the
    // analyser cannot detect the link. It is required on purpose (see composer-unused.php).
    ->ignoreErrorsOnPackage('helgesverre/toon', [ComposerDependencyAnalyser\Config\ErrorType::UNUSED_DEPENDENCY])
    // service() is declared in a file autoload of symfony/dependency-injection, which the
    // analyser does not index, so it cannot resolve the symbol to its package.
    ->ignoreErrorsOnPath(
        $rootPath.'/Configuration/Mate.php',
        [ComposerDependencyAnalyser\Config\ErrorType::UNKNOWN_FUNCTION],
    )
    // pcntl and posix stay undeclared on purpose: both call sites skip their test when the
    // extension is missing, so requiring them would turn an optional check into a hard
    // dependency of the test suite.
    ->ignoreErrorsOnExtensionAndPath(
        'ext-pcntl',
        $rootPath.'/Tests/Unit/Command/Support/McpJsonRegistrarTest.php',
        [ComposerDependencyAnalyser\Config\ErrorType::SHADOW_DEPENDENCY],
    )
    ->ignoreErrorsOnExtensionAndPath(
        'ext-posix',
        $rootPath.'/Tests/Functional/Command/ExtensionScannerCommandTest.php',
        [ComposerDependencyAnalyser\Config\ErrorType::SHADOW_DEPENDENCY],
    )
;

return $configuration;
