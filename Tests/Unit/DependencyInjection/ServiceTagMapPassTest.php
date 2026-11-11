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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\DependencyInjection;

use ArrayObject;
use KonradMichalik\Typo3AiMate\DependencyInjection\ServiceTagMapPass;
use KonradMichalik\Typo3AiMate\Service\ServiceTagMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * ServiceTagMapPassTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ServiceTagMapPassTest extends TestCase
{
    #[Test]
    public function capturesTaggedServicesIncludingPrivateOnesThatCompilationRemoves(): void
    {
        $builder = $this->builder();
        $builder->register('acme.public', stdClass::class)->setPublic(true)
            ->addTag('acme.tag', ['priority' => 10])
            ->addTag('acme.tag', ['priority' => 20]);
        $builder->register('acme.private', ArrayObject::class)
            ->addTag('acme.tag', ['identifier' => 'private-one']);

        $map = $this->compile($builder);

        self::assertFalse($builder->has('acme.private'), 'The unused private service is removed by compilation.');
        self::assertSame([
            ['id' => 'acme.private', 'class' => ArrayObject::class, 'public' => false, 'attributes' => [['identifier' => 'private-one']]],
            ['id' => 'acme.public', 'class' => stdClass::class, 'public' => true, 'attributes' => [['priority' => 10], ['priority' => 20]]],
        ], $map->services('acme.tag'));
    }

    #[Test]
    public function keepsPercentSignsInAttributesLiteral(): void
    {
        $builder = $this->builder();
        $builder->setParameter('secret', 'resolved-value');
        $builder->register('acme.literal', stdClass::class)->addTag('acme.tag', ['pattern' => '%secret% at 50%']);

        $map = $this->compile($builder);

        self::assertSame([['pattern' => '%secret% at 50%']], $map->services('acme.tag')[0]['attributes']);
    }

    #[Test]
    public function skipsAbstractAndExcludedDefinitions(): void
    {
        $builder = $this->builder();
        $builder->register('acme.abstract', stdClass::class)->setAbstract(true)->addTag('acme.tag');
        $builder->register('acme.excluded', stdClass::class)->addTag('container.excluded')->addTag('acme.tag');

        $map = $this->compile($builder);

        self::assertSame([], $map->services('acme.tag'));
        self::assertNotContains('container.excluded', $map->tagNames());
    }

    #[Test]
    public function listsTagNamesSorted(): void
    {
        $builder = $this->builder();
        $builder->register('acme.b', stdClass::class)->addTag('zeta')->addTag('alpha');

        $map = $this->compile($builder);

        self::assertSame(['alpha', 'zeta'], array_values(array_intersect($map->tagNames(), ['alpha', 'zeta'])));
        self::assertSame($map->tagNames(), array_values(array_unique($map->tagNames())));
    }

    #[Test]
    public function doesNothingWithoutAMapDefinition(): void
    {
        $builder = new ContainerBuilder();
        $builder->register('acme.public', stdClass::class)->setPublic(true)->addTag('acme.tag');
        $builder->addCompilerPass(new ServiceTagMapPass(), PassConfig::TYPE_BEFORE_REMOVING, -1000);

        $builder->compile();

        self::assertFalse($builder->has(ServiceTagMap::class));
    }

    private function builder(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->register(ServiceTagMap::class, ServiceTagMap::class)->setPublic(true);
        $builder->addCompilerPass(new ServiceTagMapPass(), PassConfig::TYPE_BEFORE_REMOVING, -1000);

        return $builder;
    }

    private function compile(ContainerBuilder $builder): ServiceTagMap
    {
        $builder->compile();
        $map = $builder->get(ServiceTagMap::class);
        self::assertInstanceOf(ServiceTagMap::class, $map);

        return $map;
    }
}
