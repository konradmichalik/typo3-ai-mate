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

namespace KonradMichalik\Typo3AiMate\DependencyInjection;

use KonradMichalik\Typo3AiMate\Service\ServiceTagMap;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition};

use function array_map;
use function array_values;
use function ksort;
use function usort;

/**
 * ServiceTagMapPass.
 *
 * Runs before the removing passes, so private services that are later inlined or dropped
 * as unused still carry their tags here. The map is written into the definition of
 * {@see ServiceTagMap} and dumped with the container, which keeps no tags of its own.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ServiceTagMapPass implements CompilerPassInterface
{
    private const EXCLUDED_TAG = 'container.excluded';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ServiceTagMap::class)) {
            return;
        }

        $map = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->hasTag(self::EXCLUDED_TAG)) {
                continue;
            }

            foreach ($definition->getTags() as $tag => $attributes) {
                $map[Cast::string($tag)][] = $this->entry($id, $definition, $attributes);
            }
        }

        ksort($map);
        $map = array_map(self::sortById(...), $map);

        // The dumper reads "%name%" in string arguments as parameter references.
        $container->getDefinition(ServiceTagMap::class)
            ->setArguments([$container->getParameterBag()->escapeValue($map)]);
    }

    /**
     * @return array{id: string, class: string, public: bool, attributes: list<array<mixed>>}
     */
    private function entry(string $id, Definition $definition, mixed $attributes): array
    {
        return [
            'id' => $id,
            'class' => $definition->getClass() ?? $id,
            'public' => $definition->isPublic(),
            'attributes' => array_values(array_map(Cast::array(...), Cast::array($attributes))),
        ];
    }

    /**
     * @param list<array{id: string, class: string, public: bool, attributes: list<array<mixed>>}> $services
     *
     * @return list<array{id: string, class: string, public: bool, attributes: list<array<mixed>>}>
     */
    private static function sortById(array $services): array
    {
        usort($services, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $services;
    }
}
