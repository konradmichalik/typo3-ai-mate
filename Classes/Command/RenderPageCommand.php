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
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

use function count;
use function is_numeric;
use function is_string;
use function sprintf;
use function strlen;

/**
 * RenderPageCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:fe:render',
    description: 'Render a frontend page via an internal HTTP request and report status plus newly logged entries as JSON.',
)]
final class RenderPageCommand extends AbstractJsonCommand
{
    /**
     * Per-request wall-clock cap so a hanging frontend request fails fast with a
     * readable error instead of stalling until the 120 s process timeout.
     */
    private const REQUEST_TIMEOUT_SECONDS = 60;

    private readonly LogsCommand $logSearch;

    public function __construct(
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct();
        $this->logSearch = new LogsCommand();
    }

    /**
     * Keep only entries logged at or after the boundary (i.e. during the render).
     * Deduplication and message/trace capping are left to LogsCommand::aggregate.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    public function newEntriesSince(array $entries, int $boundary): array
    {
        return array_values(array_filter($entries, static function (array $entry) use ($boundary): bool {
            $time = strtotime(Cast::string($entry['time'] ?? ''));

            return false !== $time && $time >= $boundary;
        }));
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::OPTIONAL, 'Page UID to render (resolved to its speaking URL via the site configuration)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Explicit URL to render instead of resolving one from a page id')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Site language id used when resolving the URL from a page id', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageId = is_numeric($input->getArgument('pageId')) ? (int) $input->getArgument('pageId') : null;

        [$url, $error] = $this->resolveTargetUrl(
            $pageId,
            $this->stringOption($input->getOption('url')),
            Cast::int($input->getOption('language')),
        );
        if (null === $url) {
            return $this->emit($output, ['error' => $error ?? 'Unable to resolve a URL to render.'], Command::FAILURE);
        }

        $boundary = time();
        $started = microtime(true);
        $request = $this->performRequest($url);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $matched = $this->newEntriesSince($this->logSearch->allEntries(), $boundary);
        $summary = $this->logSearch->aggregate($matched);

        $payload = [
            'url' => $url,
            'pageId' => $pageId,
            'status' => $request['status'],
            'durationMs' => $durationMs,
            'bytes' => $request['bytes'],
            'logs' => [
                'totalMatched' => count($matched),
                'distinct' => count($summary),
                'entries' => $summary,
            ],
        ];
        if (null !== $request['error']) {
            $payload['requestError'] = $request['error'];
        }

        return $this->emit($output, $payload);
    }

    /**
     * Resolve the URL to render from an explicit --url or a page id.
     *
     * @return array{0: string|null, 1: string|null} [url, error] — error is set only when url is null
     */
    private function resolveTargetUrl(?int $pageId, ?string $urlOption, int $language): array
    {
        if (null !== $urlOption) {
            return $this->resolveExplicitUrl($urlOption);
        }
        if (null !== $pageId) {
            $url = $this->siteUrlResolver->urlForPage($pageId, $language);

            return [$url, null === $url ? sprintf('Could not resolve a URL for page %d (no site configuration?).', $pageId) : null];
        }

        return [null, 'Pass a pageId argument or a --url to render.'];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveExplicitUrl(string $urlOption): array
    {
        if (str_contains($urlOption, '://') && !$this->siteUrlResolver->isAllowedHost($urlOption)) {
            return [null, sprintf('URL host is not among the configured site bases: %s', $urlOption)];
        }
        $url = $this->absoluteUrl($urlOption);

        return [$url, null === $url ? sprintf('Relative URL "%s" needs a site base — pass an absolute URL or a pageId.', $urlOption) : null];
    }

    /**
     * Pass absolute URLs through; resolve a relative one against the first site
     * that has an absolute base (Guzzle rejects a scheme-less URL like "/").
     */
    private function absoluteUrl(string $url): ?string
    {
        if (str_contains($url, '://')) {
            return $url;
        }
        $base = $this->siteUrlResolver->firstSiteBase();

        return null === $base ? null : rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * @return array{status: int, bytes: int, error: string|null}
     */
    private function performRequest(string $url): array
    {
        try {
            // http_errors=false so a 500 error page (a common deprecation trigger)
            // still yields a response with its status rather than throwing.
            // allow_redirects=false so a redirect cannot pivot the request to an
            // off-site (or internal) host after the initial host allowlist check.
            $response = $this->requestFactory->request($url, 'GET', ['http_errors' => false, 'allow_redirects' => false, 'timeout' => self::REQUEST_TIMEOUT_SECONDS]);
            $body = $response->getBody();

            return ['status' => $response->getStatusCode(), 'bytes' => $body->getSize() ?? strlen((string) $body), 'error' => null];
        } catch (Throwable $exception) {
            return ['status' => 0, 'bytes' => 0, 'error' => $exception->getMessage()];
        }
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
