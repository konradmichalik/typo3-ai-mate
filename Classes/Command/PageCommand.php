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

use KonradMichalik\Typo3AiMate\Service\TypoScriptResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * PageCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
#[AsCommand(
    name: 'typo3-ai-mate:page:info',
    description: 'Page composition (content elements, backend layout) and cache signals as JSON.',
)]
final class PageCommand extends AbstractJsonCommand
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly TypoScriptResolver $typoScriptResolver,
    ) {
        parent::__construct();
    }

    /**
     * Match a page's content elements against the resolved page TypoScript to find
     * which plugins render as USER_INT (uncached) — the most common cause of slow
     * pages (tt_content.list.20.<signature> / tt_content.<CType> = USER_INT).
     *
     * @param array<mixed>               $setup           resolved page TypoScript
     * @param list<array<string, mixed>> $contentElements
     *
     * @return list<string>
     */
    public static function matchUserIntPlugins(array $setup, array $contentElements): array
    {
        /** @var array<string, mixed> $ttContent */
        $ttContent = is_array($setup['tt_content.'] ?? null) ? $setup['tt_content.'] : [];
        /** @var array<string, mixed> $listConf */
        $listConf = is_array($ttContent['list.'] ?? null) && is_array($ttContent['list.']['20.'] ?? null)
            ? $ttContent['list.']['20.']
            : [];

        $userInt = [];
        foreach ($contentElements as $element) {
            $signature = $element['plugin'] ?? null;
            if (is_string($signature) && ($listConf[$signature] ?? null) === 'USER_INT') {
                $userInt[$signature] = true;
                continue;
            }
            $cType = Cast::string($element['CType'] ?? '');
            if (($ttContent[$cType] ?? null) === 'USER_INT') {
                $userInt[$cType] = true;
            }
        }

        return array_keys($userInt);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::OPTIONAL, 'Page UID (primary; usually taken from a profile)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Best-effort URL to resolve to a page id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageId = $this->resolvePageId($input);
        if (null === $pageId) {
            return $this->emit($output, ['error' => 'No resolvable page id (pass a pageId argument or a matching --url).'], Command::FAILURE);
        }

        $pageRow = $this->fetchPageRow($pageId);
        if (null === $pageRow) {
            return $this->emit($output, ['error' => sprintf('Page %d not found.', $pageId)], Command::FAILURE);
        }

        $contentElements = $this->fetchContentElements($pageId);

        return $this->emit($output, [
            'page' => [
                'id' => $pageId,
                'title' => $pageRow['title'] ?? null,
                'backend_layout' => $pageRow['backend_layout'] ?? '',
            ],
            'cache' => [
                // pages.no_cache was removed in TYPO3 v12; only cache_timeout remains.
                'cache_timeout' => Cast::int($pageRow['cache_timeout'] ?? 0),
            ],
            'content_elements' => $contentElements,
            'user_int_plugins' => $this->detectUserIntPlugins($pageId, $contentElements),
        ]);
    }

    private function resolvePageId(InputInterface $input): ?int
    {
        $pageId = $input->getArgument('pageId');
        if (is_numeric($pageId)) {
            return (int) $pageId;
        }

        // Best-effort URL -> id resolution via the site configuration.
        $url = $input->getOption('url');
        if (!is_string($url) || '' === $url) {
            return null;
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $base = rtrim((string) $site->getBase(), '/');
                if ('' !== $base && str_starts_with($url, $base)) {
                    return $site->getRootPageId();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageRow(int $pageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('uid', 'title', 'backend_layout', 'cache_timeout')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchContentElements(int $pageId): array
    {
        // list_type is the classic plugin signature; it was removed from tt_content
        // in v14 (plugins use a dedicated CType there), so only select it on v13.
        $tca = Cast::array($GLOBALS['TCA'] ?? null);
        $ttContentColumns = Cast::array(Cast::array($tca['tt_content'] ?? null)['columns'] ?? null);
        $hasListType = array_key_exists('list_type', $ttContentColumns);
        $columns = ['uid', 'colPos', 'CType', 'header', 'hidden'];
        if ($hasListType) {
            $columns[] = 'list_type';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select(...$columns)
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $plugin = Cast::string($row['list_type'] ?? '');

            return [
                'uid' => Cast::int($row['uid'] ?? 0),
                'colPos' => Cast::int($row['colPos'] ?? 0),
                'CType' => Cast::string($row['CType'] ?? ''),
                'plugin' => '' !== $plugin ? $plugin : null,
                'header' => Cast::string($row['header'] ?? ''),
                'hidden' => (bool) ($row['hidden'] ?? false),
            ];
        }, $rows);
    }

    /**
     * Determine which of the page's plugins render as USER_INT (uncached) — the
     * most common cause of slow pages. Resolved from the page TypoScript
     * (tt_content.list.20.<signature> / tt_content.<CType> = USER_INT).
     *
     * @param list<array<string, mixed>> $contentElements
     *
     * @return list<string>|null null when TypoScript resolution is unavailable
     */
    private function detectUserIntPlugins(int $pageId, array $contentElements): ?array
    {
        try {
            $setup = $this->typoScriptResolver->resolveSetup($pageId);
        } catch (Throwable) {
            return null;
        }

        return self::matchUserIntPlugins($setup, $contentElements);
    }
}
