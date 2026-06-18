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

use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

use function array_map;
use function array_slice;
use function count;
use function is_numeric;
use function is_string;
use function sprintf;
use function strlen;

/**
 * RenderPageCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:fe:render',
    description: 'Render a frontend page via an internal HTTP request and report status plus newly logged entries as JSON.',
)]
final class RenderPageCommand extends AbstractJsonCommand
{
    private const MAX_ENTRIES = 50;
    private const TRACE_LIMIT = 2000;

    private readonly LogsCommand $logSearch;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct();
        $this->logSearch = new LogsCommand();
    }

    /**
     * Keep entries logged at or after the boundary, most recent last, capped and
     * with truncated traces (a single render must not flood the output).
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    public function newEntriesSince(array $entries, int $boundary, int $cap, int $traceLimit): array
    {
        $matched = array_values(array_filter($entries, static function (array $entry) use ($boundary): bool {
            $time = strtotime(Cast::string($entry['time'] ?? ''));

            return false !== $time && $time >= $boundary;
        }));

        return array_map(
            fn (array $entry): array => $this->truncateTrace($entry, $traceLimit),
            array_slice($matched, -$cap),
        );
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
        $url = $this->stringOption($input->getOption('url'));

        if (null === $url) {
            if (null === $pageId) {
                return $this->emit($output, ['error' => 'Pass a pageId argument or a --url to render.'], Command::FAILURE);
            }
            $url = $this->urlForPage($pageId, Cast::int($input->getOption('language')));
            if (null === $url) {
                return $this->emit($output, ['error' => sprintf('Could not resolve a URL for page %d (no site configuration?).', $pageId)], Command::FAILURE);
            }
        }

        $boundary = time();
        $started = microtime(true);
        $request = $this->performRequest($url);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $entries = $this->newEntriesSince($this->logSearch->allEntries(), $boundary, self::MAX_ENTRIES, self::TRACE_LIMIT);

        $payload = [
            'url' => $url,
            'pageId' => $pageId,
            'status' => $request['status'],
            'durationMs' => $durationMs,
            'bytes' => $request['bytes'],
            'logs' => [
                'count' => count($entries),
                'entries' => $entries,
            ],
        ];
        if (null !== $request['error']) {
            $payload['requestError'] = $request['error'];
        }

        return $this->emit($output, $payload);
    }

    private function urlForPage(int $pageId, int $language): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);

            return (string) $site->getRouter()->generateUri($pageId, ['_language' => $language]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{status: int, bytes: int, error: string|null}
     */
    private function performRequest(string $url): array
    {
        try {
            // http_errors=false so a 500 error page (a common deprecation trigger)
            // still yields a response with its status rather than throwing.
            $response = $this->requestFactory->request($url, 'GET', ['http_errors' => false, 'allow_redirects' => true]);
            $body = $response->getBody();

            return ['status' => $response->getStatusCode(), 'bytes' => $body->getSize() ?? strlen((string) $body), 'error' => null];
        } catch (Throwable $exception) {
            return ['status' => 0, 'bytes' => 0, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function truncateTrace(array $entry, int $traceLimit): array
    {
        if (0 === $traceLimit || !isset($entry['trace'])) {
            return $entry;
        }
        $trace = Cast::string($entry['trace']);
        if (mb_strlen($trace) <= $traceLimit) {
            return $entry;
        }

        return array_merge($entry, ['trace' => mb_substr($trace, 0, $traceLimit).'…[truncated]']);
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
