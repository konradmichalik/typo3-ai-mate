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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;

use function count;
use function is_array;

/**
 * FluidNamespacesCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:fluid:namespaces',
    description: 'Global Fluid ViewHelper namespaces available in every template without an xmlns declaration, as JSON.',
)]
final class FluidNamespacesCommand extends AbstractJsonCommand
{
    public function __construct(private readonly ViewHelperResolverFactoryInterface $viewHelperResolverFactory)
    {
        parent::__construct();
    }

    /**
     * The resolved namespace map, prefix => PHP namespaces in resolution order.
     *
     * Taken from the ViewHelperResolver rather than reassembled from its inputs:
     * v14 merges `Configuration/Fluid/Namespaces.php` of every package with the
     * (deprecated) TYPO3_CONF_VARS registration and then lets listeners rewrite
     * the result via ModifyNamespacesEvent. Only the resolver knows the outcome.
     *
     * @return array<string, list<string>>
     */
    public function describeNamespaces(): array
    {
        $namespaces = [];
        foreach ($this->viewHelperResolverFactory->create()->getNamespaces() as $prefix => $phpNamespaces) {
            // A prefix explicitly mapped to null is Fluid's way of ignoring it
            // (e.g. xmlns:xsi), which is an answer worth reporting as such.
            $namespaces[Cast::string($prefix)] = is_array($phpNamespaces)
                ? array_values(array_map(static fn (mixed $value): string => Cast::string($value), $phpNamespaces))
                : [];
        }
        ksort($namespaces);

        return $namespaces;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespaces = $this->describeNamespaces();

        return $this->emit($output, [
            'count' => count($namespaces),
            'namespaces' => $namespaces,
            '_hint' => 'These prefixes resolve in every template without being declared, so a template knows which ones it may use. Every other namespace has to be declared per template with an xmlns attribute. A prefix with an empty list is registered as ignored.',
        ]);
    }
}
