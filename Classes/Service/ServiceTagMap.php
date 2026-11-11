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

use function array_keys;

/**
 * ServiceTagMap.
 *
 * Tag name => tagged services, captured by {@see \KonradMichalik\Typo3AiMate\DependencyInjection\ServiceTagMapPass}
 * while the container is built and dumped with it, because the compiled container keeps no tags.
 *
 * @phpstan-type TaggedService array{id: string, class: string, public: bool, attributes: list<array<mixed>>}
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ServiceTagMap
{
    /**
     * @param array<string, list<TaggedService>> $tags
     */
    public function __construct(private array $tags = []) {}

    /**
     * @return list<TaggedService>
     */
    public function services(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return array_keys($this->tags);
    }
}
