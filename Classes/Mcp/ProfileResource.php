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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

use function array_key_exists;
use function sprintf;

/**
 * ProfileResource.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ProfileResource
{
    public function __construct(private ProfileProvider $profiles) {}

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[McpResourceTemplate(uriTemplate: 'typo3-profiler://profile/{token}', name: 'typo3-profile', description: 'Full request profile by token — all sections (queries, timing, cache, events, log, page, …).', mimeType: 'text/plain')]
    public function profile(string $token): array
    {
        $profile = $this->profiles->rawByToken($token);
        $payload = null === $profile
            ? ['error' => sprintf('Profile "%s" not found.', $token)]
            : $this->profiles->annotate($profile);

        return [
            'uri' => $this->profiles->resourceUri($token),
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($payload),
        ];
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[McpResourceTemplate(uriTemplate: 'typo3-profiler://profile/{token}/{section}', name: 'typo3-profile-section', description: 'A single section of a request profile (e.g. queries, duplicate_queries, timing, cache, events, log, page, memory, php, slow_queries).', mimeType: 'text/plain')]
    public function section(string $token, string $section): array
    {
        $profile = $this->profiles->rawByToken($token);
        if (null === $profile) {
            $payload = ['error' => sprintf('Profile "%s" not found.', $token)];
        } elseif (!array_key_exists($section, $profile)) {
            $payload = ['error' => sprintf('Section "%s" not present in profile "%s".', $section, $token)];
        } else {
            $payload = [$section => $profile[$section]];
        }

        return [
            'uri' => $this->profiles->resourceUri($token).'/'.$section,
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($payload),
        ];
    }
}
