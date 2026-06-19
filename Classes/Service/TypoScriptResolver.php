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

namespace KonradMichalik\Typo3AiMate\Service;

use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\{GeneralUtility, RootlineUtility};

use function sprintf;

/**
 * TypoScriptResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TypoScriptResolver
{
    public function __construct(
        private FrontendTypoScriptFactory $factory,
        private SysTemplateRepository $sysTemplateRepository,
        private SiteFinder $siteFinder,
        private CacheManager $cacheManager,
    ) {}

    /**
     * @return array<mixed>
     */
    public function resolveSetup(int $pageId): array
    {
        return $this->resolve($pageId)['setup'];
    }

    /**
     * @return array<mixed>
     */
    public function resolveConstants(int $pageId): array
    {
        return $this->resolve($pageId)['constants'];
    }

    /**
     * @return array{setup: array<mixed>, constants: array<mixed>}
     */
    private function resolve(int $pageId): array
    {
        try {
            $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf('Could not build rootline for page %d: %s', $pageId, $exception->getMessage()), 1718000001, $exception);
        }

        $site = $this->siteFinder->getSiteByPageId($pageId);
        $request = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\ServerRequest::class)
            ->withAttribute('site', $site);

        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootLine, $request);

        /** @var PhpFrontend $typoScriptCache */
        $typoScriptCache = $this->cacheManager->getCache('typoscript');

        // Settings (constants) + setup-conditions tree, then the full setup AST.
        $frontendTypoScript = $this->factory->createSettingsAndSetupConditions(
            $site,
            $sysTemplateRows,
            [],
            $typoScriptCache,
        );

        $frontendTypoScript = $this->factory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $site,
            $sysTemplateRows,
            [],
            '0',
            $typoScriptCache,
            null,
        );

        return [
            'setup' => $frontendTypoScript->getSetupArray(),
            // Constants resolve to a flat key => value map (getFlatSettings).
            'constants' => $frontendTypoScript->getFlatSettings(),
        ];
    }
}
