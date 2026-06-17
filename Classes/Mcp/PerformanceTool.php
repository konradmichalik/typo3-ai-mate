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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

use function sprintf;

/**
 * PerformanceTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class PerformanceTool
{
    public function __construct(private ProfileProvider $profiles) {}

    #[McpTool(name: 'typo3-profiler-latest', description: 'Compact summary of the most recent request profile (timing, query count, N+1, cache, page.id) plus a resource_uri to read the full profile. Primary tool for a "slow page".')]
    public function latest(): string
    {
        $profile = $this->profiles->rawLatest();

        return ResponseEncoder::encode(null === $profile
            ? ['error' => 'No profiles found. Trigger a frontend request in the Development context first.']
            : $this->profiles->summarize($profile));
    }

    #[McpTool(name: 'typo3-profiler-list', description: 'List the most recent request profiles as compact summaries (token, url, status, timing, queries, cache), each with a resource_uri for the full profile.')]
    public function list(int $limit = 20): string
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ResponseEncoder::encode(['profiles' => $this->profiles->summaries($limit)]);
    }

    #[McpTool(name: 'typo3-profiler-search', description: 'Search request profiles by url substring and/or HTTP status; returns matching summaries (with resource_uri), newest first.')]
    public function search(?string $url = null, ?int $status = null, int $limit = 20): string
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ResponseEncoder::encode(['profiles' => $this->profiles->search($url, $status, $limit)]);
    }

    #[McpTool(name: 'typo3-profiler-get', description: 'Compact summary of a single request profile by its token (= request_id, correlates with logs), plus a resource_uri to read the full profile.')]
    public function get(string $token): string
    {
        $profile = $this->profiles->rawByToken($token);

        return ResponseEncoder::encode(null === $profile
            ? ['error' => sprintf('Profile "%s" not found.', $token)]
            : $this->profiles->summarize($profile));
    }
}
