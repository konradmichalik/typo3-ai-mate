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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Fixtures;

use Closure;
use Psr\Container\{ContainerInterface, NotFoundExceptionInterface};
use RuntimeException;

/**
 * InMemoryContainer.
 *
 * A minimal PSR-11 container test double: registered ids resolve via a factory closure,
 * anything else answers has()=false, exactly like a real container's private/missing
 * services do. $shared controls whether repeated get() calls for the same id are cached
 * (the default, matching TYPO3's own shared-by-default services) or produce a fresh
 * instance every time.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class InMemoryContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * @param array<string, Closure(): object> $factories
     */
    public function __construct(
        private readonly array $factories,
        private readonly bool $shared = true,
    ) {}

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class extends RuntimeException implements NotFoundExceptionInterface {};
        }

        if ($this->shared) {
            return $this->resolved[$id] ??= ($this->factories[$id])();
        }

        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
