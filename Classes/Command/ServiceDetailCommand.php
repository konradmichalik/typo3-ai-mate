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

use KonradMichalik\Typo3AiMate\Support\Cast;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_object;
use function sprintf;

/**
 * ServiceDetailCommand.
 *
 * Reads the already-compiled, already-booted DI container via the publicly aliased
 * {@see ContainerInterface} (TYPO3 core: Psr\Container\ContainerInterface -> service_container,
 * public: true) rather than rebuilding a ContainerBuilder: TYPO3's own bootstrap feeds that
 * rebuild nine synthetic early instances (ClassLoader, ApplicationContext, ConfigurationManager,
 * cache.core, cache.di, ...) that a console command has no clean way to reproduce, several of
 * them via @internal APIs. Reading the live container instead only needs public API
 * (ContainerInterface::has()/get(), PHP Reflection on the resolved instance), at the cost of two
 * things a compiled Definition would still have: the compiler-only flags (lazy, autowired,
 * autoconfigured) and constructor arguments as TYPO3 resolved them (a service reference vs. a
 * literal parameter) rather than as PHP declares them (a type only).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:service:detail',
    description: 'Class, constructor argument types, and public/shared flags of one DI service (public services only).',
)]
final class ServiceDetailCommand extends AbstractJsonCommand
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Service id (a class/interface name for an autowired service, or a string id) to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = Cast::string($input->getArgument('id'));

        if (!$this->container->has($id)) {
            return $this->emit($output, ['error' => sprintf('Service "%s" is not registered or not public. Only public services can be inspected; TYPO3 makes most services private by default.', $id)], Command::FAILURE);
        }

        try {
            $service = $this->container->get($id);
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => sprintf('Could not resolve service "%s": %s', $id, $exception->getMessage())], Command::FAILURE);
        }

        // ContainerInterface::get() is typed mixed by the PSR-11 interface itself; every real
        // TYPO3 service is an object, but nothing stops a container from allowing anything else.
        if (!is_object($service)) {
            return $this->emit($output, ['error' => sprintf('Service "%s" resolved to a non-object value.', $id)], Command::FAILURE);
        }

        $shared = $service === $this->container->get($id);

        return $this->emit($output, [
            'id' => $id,
            'class' => $service::class,
            'shared' => $shared,
            'constructorArguments' => $this->constructorArguments($service::class),
        ]);
    }

    /**
     * @param class-string $class
     *
     * @return list<array{position: int, name: string, type: string}>
     */
    private function constructorArguments(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        if (null === $constructor) {
            return [];
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $position => $parameter) {
            $arguments[] = [
                'position' => $position,
                'name' => $parameter->getName(),
                'type' => $this->typeName($parameter->getType()),
            ];
        }

        return $arguments;
    }

    private function typeName(?ReflectionType $type): string
    {
        if (!$type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        return ($type->allowsNull() && 'null' !== $type->getName() ? '?' : '').$type->getName();
    }
}
