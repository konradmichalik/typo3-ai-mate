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
use Symfony\AI\Mate\Attribute\MateResourceTemplate;
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
    #[MateResourceTemplate(uriTemplate: 'typo3-profiler://profile/{token}', name: 'typo3-profile', description: 'Full request profile by token — all sections (queries, timing, cache, events, log, page, …).', mimeType: 'text/plain')]
    public function profile(string $token): array
    {
        $profile = $this->profiles->rawByToken($token);

        return [
            'uri' => $this->profiles->resourceUri($token),
            'mimeType' => 'text/plain',
            'text' => null === $profile
                ? ResponseEncoder::encode(['error' => sprintf('Profile "%s" not found.', $token)])
                : ResponseEncoder::encodeUntrusted($this->profiles->annotate($profile)),
        ];
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[MateResourceTemplate(uriTemplate: 'typo3-profiler://profile/{token}/{section}', name: 'typo3-profile-section', description: 'A single section of a request profile (e.g. queries, duplicate_queries, timing, cache, events, log, page, memory, php, slow_queries).', mimeType: 'text/plain')]
    public function section(string $token, string $section): array
    {
        $profile = $this->profiles->rawByToken($token);
        $uri = $this->profiles->resourceUri($token).'/'.$section;

        if (null === $profile) {
            return ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => ResponseEncoder::encode(['error' => sprintf('Profile "%s" not found.', $token)])];
        }
        if (!array_key_exists($section, $profile)) {
            return ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => ResponseEncoder::encode(['error' => sprintf('Section "%s" not present in profile "%s".', $section, $token)])];
        }

        return ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => ResponseEncoder::encodeUntrusted([$section => $profile[$section]])];
    }
}
