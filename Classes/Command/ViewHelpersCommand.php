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

use Composer\Autoload\ClassLoader;
use KonradMichalik\Typo3AiMate\Command\Support\{IconLookup, ViewHelperCatalog, ViewHelperLookup};
use KonradMichalik\Typo3AiMate\Support\{Cast, ClassLoaders};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Fluid\Core\ViewHelper\{ViewHelperResolver, ViewHelperResolverFactoryInterface};
use TYPO3Fluid\Fluid\Core\Parser\Exception as ParserException;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

use function array_key_exists;
use function count;
use function is_array;
use function sprintf;

/**
 * ViewHelpersCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:fluid:viewhelpers',
    description: 'Whether Fluid ViewHelpers exist, with their class and registered arguments, or the ViewHelper names of one global namespace, as JSON.',
)]
final class ViewHelpersCommand extends AbstractJsonCommand
{
    public function __construct(
        private readonly ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private readonly ClassLoader $classLoader,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('names', null, InputOption::VALUE_REQUIRED, 'Comma-separated ViewHelper names with their prefix, e.g. f:link.page,f:format.date');
        $this->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'List the ViewHelper names of this global prefix instead, e.g. f');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resolver = $this->viewHelperResolverFactory->create();
        $namespaces = $resolver->getNamespaces();

        $names = IconLookup::parseList($input->getOption('names'));
        if ([] !== $names) {
            $described = [];
            foreach ($names as $name) {
                $described[$name] = $this->describe($resolver, $namespaces, $name);
            }

            return $this->emit($output, ['checked' => count($names), 'viewHelpers' => $described]);
        }

        $prefix = trim(Cast::string($input->getOption('namespace')));
        if ('' !== $prefix) {
            return $this->emit($output, $this->listNamespace($namespaces, $prefix));
        }

        return $this->emit($output, [
            'namespaces' => $this->prefixes($namespaces),
            '_hint' => 'Pass names=<f:link.page,…> to check ViewHelpers and get their arguments, or namespace=<f> to list the ViewHelper names of one prefix.',
        ]);
    }

    /**
     * @param array<array-key, mixed> $namespaces
     *
     * @return array<string, mixed>
     */
    private function describe(ViewHelperResolver $resolver, array $namespaces, string $name): array
    {
        $parsed = ViewHelperLookup::parseName($name);
        if (null === $parsed) {
            return ['valid' => false, '_hint' => 'Not a ViewHelper name. Expected <prefix>:<name>, e.g. f:link.page.'];
        }

        ['prefix' => $prefix, 'name' => $shortName] = $parsed;
        // Checked first: Fluid 4 reads the namespace map unguarded and warns on an unknown prefix.
        $unavailable = $this->unavailableNamespace($namespaces, $prefix);
        if (null !== $unavailable) {
            return ['exists' => false, ...$unavailable];
        }

        try {
            $className = $resolver->resolveViewHelperClassName($prefix, $shortName);
        } catch (ParserException) {
            return [
                'exists' => false,
                'suggestions' => array_map(
                    static fn (string $candidate): string => $prefix.':'.$candidate,
                    IconLookup::suggest($shortName, ViewHelperCatalog::names($namespaces[$prefix], ClassLoaders::with($this->classLoader))['names']),
                ),
                '_hint' => sprintf('<%s> does not resolve to a ViewHelper class, so the template fails to parse.', $name),
            ];
        }

        return ['exists' => true, 'class' => $className, ...$this->arguments($resolver, $className)];
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(ViewHelperResolver $resolver, string $className): array
    {
        if (!ViewHelperCatalog::isViewHelper($className)) {
            return ['error' => sprintf('%s is not an instantiable ViewHelper.', $className)];
        }

        try {
            $viewHelper = $resolver->createViewHelperInstanceFromClassName($className);
            $definitions = $resolver->getArgumentDefinitionsForViewHelper($viewHelper);
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }

        $arguments = [];
        foreach ($definitions as $definition) {
            $arguments[$definition->getName()] = ViewHelperLookup::describeArgument($definition);
        }
        ksort($arguments);

        return array_filter([
            'arguments' => $arguments,
            // Tag-based ViewHelpers render any undeclared argument as a tag attribute.
            'acceptsUndeclaredArguments' => $viewHelper instanceof AbstractTagBasedViewHelper ?: null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<array-key, mixed> $namespaces
     *
     * @return array<string, mixed>
     */
    private function listNamespace(array $namespaces, string $prefix): array
    {
        $unavailable = $this->unavailableNamespace($namespaces, $prefix);
        if (null !== $unavailable) {
            return ['namespace' => $prefix, 'exists' => false, ...$unavailable];
        }

        $listing = ViewHelperCatalog::names($namespaces[$prefix], ClassLoaders::with($this->classLoader));

        return array_filter([
            'namespace' => $prefix,
            'count' => count($listing['names']),
            'viewHelpers' => $listing['names'],
            'unlisted' => $listing['unlisted'],
            '_hint' => [] !== $listing['unlisted']
                ? 'unlisted PHP namespaces resolve through a resolver delegate, which cannot enumerate its ViewHelpers. Check a name with names=<prefix:name> instead.'
                : null,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * Only the global map is known here; a namespace a template declares with
     * xmlns is out of reach of a resolver built outside that template.
     *
     * @param array<array-key, mixed> $namespaces
     *
     * @return array<string, mixed>|null
     */
    private function unavailableNamespace(array $namespaces, string $prefix): ?array
    {
        if (!array_key_exists($prefix, $namespaces)) {
            return [
                'suggestions' => IconLookup::suggest($prefix, $this->prefixes($namespaces)),
                '_hint' => sprintf('"%s" is not a global namespace. A template has to declare it with an xmlns attribute first.', $prefix),
            ];
        }
        if (!is_array($namespaces[$prefix])) {
            return ['ignored' => true, '_hint' => sprintf('"%s" is registered as ignored: Fluid renders its tags as plain text.', $prefix)];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $namespaces
     *
     * @return list<string>
     */
    private function prefixes(array $namespaces): array
    {
        $prefixes = array_map(Cast::string(...), array_keys($namespaces));
        sort($prefixes);

        return $prefixes;
    }
}
