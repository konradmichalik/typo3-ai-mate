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

use KonradMichalik\Typo3AiMate\Service\SiteUrlResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};
use TYPO3\CMS\Core\Site\SiteFinder;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;

/**
 * SiteCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:site:dump',
    description: 'Configured sites (identifier, base, root page, languages, error handling), or the frontend/backend URL for a page, as JSON.',
)]
final class SiteCommand extends AbstractJsonCommand
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly UriBuilder $backendUriBuilder,
    ) {
        parent::__construct();
    }

    /**
     * @return array{identifier: string, base: string, rootPageId: int, languages: list<array<string, mixed>>, errorHandling: list<array<string, mixed>>}
     */
    public function describeSite(Site $site): array
    {
        return [
            'identifier' => $site->getIdentifier(),
            'base' => (string) $site->getBase(),
            'rootPageId' => $site->getRootPageId(),
            'languages' => array_values(array_map($this->describeLanguage(...), $site->getAllLanguages())),
            'errorHandling' => $this->describeErrorHandling($site),
        ];
    }

    /**
     * @return array{id: int, locale: string, base: string, title: string}
     */
    public function describeLanguage(SiteLanguage $language): array
    {
        return [
            'id' => $language->getLanguageId(),
            'locale' => (string) $language->getLocale(),
            'base' => (string) $language->getBase(),
            'title' => $language->getTitle(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeErrorHandling(Site $site): array
    {
        $entries = Cast::array($site->getConfiguration()['errorHandling'] ?? null);

        /** @var list<array<string, mixed>> $trimmed */
        $trimmed = array_values(array_map(
            static fn (mixed $entry): array => array_filter(Cast::array($entry), static fn (mixed $value): bool => null !== $value),
            $entries,
        ));

        return $trimmed;
    }

    protected function configure(): void
    {
        $this
            ->addOption('identifier', null, InputOption::VALUE_REQUIRED, 'Limit the default listing to a single site by identifier')
            ->addOption('pageId', null, InputOption::VALUE_REQUIRED, 'Resolve the frontend/backend URL for this page instead of listing sites; pass 0 to use the root page of the first configured site instead of naming one; omit the option entirely to list sites')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Site language id used when resolving a URL', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageIdOption = Cast::string($input->getOption('pageId'));
        if ('' !== $pageIdOption) {
            return $this->dumpUrl($output, Cast::int($pageIdOption), Cast::int($input->getOption('language')));
        }

        $identifier = Cast::string($input->getOption('identifier'));

        return '' !== $identifier ? $this->dumpOneSite($output, $identifier) : $this->dumpAllSites($output);
    }

    private function dumpAllSites(OutputInterface $output): int
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (Throwable) {
            return $this->emit($output, ['error' => 'Could not load site configuration.'], Command::FAILURE);
        }

        return $this->emit($output, ['sites' => array_map($this->describeSite(...), array_values($sites))]);
    }

    private function dumpOneSite(OutputInterface $output, string $identifier): int
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (Throwable) {
            return $this->emit($output, ['error' => sprintf('Unknown site identifier "%s".', $identifier)], Command::FAILURE);
        }

        return $this->emit($output, ['site' => $this->describeSite($site)]);
    }

    private function dumpUrl(OutputInterface $output, int $pageId, int $language): int
    {
        if (0 === $pageId) {
            $firstSite = $this->siteUrlResolver->firstSite();
            if (null === $firstSite) {
                return $this->emit($output, ['error' => 'No site is configured.'], Command::FAILURE);
            }
            $pageId = $firstSite->getRootPageId();
        }

        $frontendUrl = $this->siteUrlResolver->urlForPage($pageId, $language);
        if (null === $frontendUrl) {
            return $this->emit($output, [
                'error' => sprintf('Could not resolve a URL for page %d (no site configuration?). Omit pageId to list configured sites instead.', $pageId),
            ], Command::FAILURE);
        }

        return $this->emit($output, [
            'pageId' => $pageId,
            'languageId' => $language,
            'frontendUrl' => $frontendUrl,
            'backendUrl' => $this->backendUrlForPage($pageId),
        ]);
    }

    private function backendUrlForPage(int $pageId): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            // The backend always lives under /typo3/, but a RequestContext built
            // from the frontend site base has no notion of that entry point.
            $this->backendUriBuilder->setRequestContext(RequestContext::fromUri(rtrim((string) $site->getBase(), '/').'/typo3/'));

            return (string) $this->backendUriBuilder->buildUriFromRoute('web_layout', ['id' => $pageId], UriBuilder::ABSOLUTE_URL);
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            // Defensive: building a backend route URI for an existing page id does
            // not fail in a booted installation.
            return null;
        }
        // @codeCoverageIgnoreEnd
    }
}
