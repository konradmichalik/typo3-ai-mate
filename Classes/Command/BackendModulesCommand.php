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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;

use function count;

/**
 * BackendModulesCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:backend:modules',
    description: 'Registered backend modules with their parent and resolved navigation component, as JSON.',
)]
final class BackendModulesCommand extends AbstractJsonCommand
{
    public function __construct(private readonly ModuleProvider $moduleProvider)
    {
        parent::__construct();
    }

    /**
     * Every registered module, flat and without a user context: the question is
     * what exists in this installation, not what the current user may open.
     *
     * @return array<string, array<string, mixed>>
     */
    public function describeModules(): array
    {
        $modules = [];
        // grouped=false returns the flat registry; without a user, no access
        // filtering is applied.
        foreach ($this->moduleProvider->getModules(grouped: false) as $module) {
            $modules[$module->getIdentifier()] = array_filter([
                'parent' => $module->getParentIdentifier(),
                'path' => $module->getPath(),
                // Already resolved through the parent chain when the module
                // declares inheritNavigationComponent, which is what makes this
                // worth asking a running system for.
                'navigationComponent' => $module->getNavigationComponent(),
                'access' => $module->getAccess(),
            ], static fn (mixed $value): bool => '' !== $value);
        }
        ksort($modules);

        return $modules;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->describeModules();

        return $this->emit($output, [
            'count' => count($modules),
            'modules' => $modules,
            '_hint' => 'navigationComponent is the resolved value: a submodule declaring inheritNavigationComponent takes its parent\'s, which Configuration/Backend/Modules.php does not show. A module without a parent is a top-level entry.',
        ]);
    }
}
