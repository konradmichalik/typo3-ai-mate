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
use KonradMichalik\Typo3AiMate\Command\Support\ClassOwnerResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};

use function count;
use function is_array;
use function is_string;

/**
 * OverridesCommand.
 *
 * Lists the XCLASS registrations in $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'].
 * TYPO3 does not record which ext_localconf.php wrote a registration, so the
 * owner is the package the replacement class belongs to. DI service decorators
 * are not listed: the compiled container keeps no mapping from a decorated
 * service id to its decorator (see docs/tools.md#typo3-overrides).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:overrides:list',
    description: 'XCLASS registrations with the extension owning each replacement class as JSON.',
)]
final class OverridesCommand extends AbstractJsonCommand
{
    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only registrations whose original or replacement class is this class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $xclasses = $this->xclasses();

        $filter = $input->getOption('class');
        if (!is_string($filter) || '' === $filter) {
            return $this->emit($output, ['xclassCount' => count($xclasses), 'xclasses' => $xclasses]);
        }

        $class = ltrim($filter, '\\');
        $matches = array_values(array_filter(
            $xclasses,
            static fn (array $xclass): bool => 0 === strcasecmp($xclass['original'], $class) || 0 === strcasecmp($xclass['replacement'], $class),
        ));

        return $this->emit($output, ['class' => $class, 'found' => [] !== $matches, 'xclasses' => $matches]);
    }

    /**
     * @return list<array{original: string, replacement: string, effectiveClass: string, extension: string|null, package: string|null, ownerDeterminedBy: string|null}>
     */
    private function xclasses(): array
    {
        $replacements = $this->registeredReplacements();
        $owners = $this->ownerResolver();

        $xclasses = [];
        foreach ($replacements as $original => $replacement) {
            $owner = $owners->resolve($replacement);
            $xclasses[] = [
                'original' => $original,
                'replacement' => $replacement,
                'effectiveClass' => $this->effectiveClass($original, $replacements),
                'extension' => $owner['extension'],
                'package' => $owner['package'],
                'ownerDeterminedBy' => $owner['determinedBy'],
            ];
        }

        return $xclasses;
    }

    /**
     * @return array<string, string> original class => replacement class, only the ones TYPO3 honors
     */
    private function registeredReplacements(): array
    {
        $confVars = Cast::array($GLOBALS['TYPO3_CONF_VARS'] ?? null);
        $objects = Cast::array(Cast::array($confVars['SYS'] ?? null)['Objects'] ?? null);

        $replacements = [];
        foreach ($objects as $original => $registration) {
            $replacement = is_array($registration) ? Cast::string($registration['className'] ?? null) : '';
            if ('' !== $replacement) {
                $replacements[(string) $original] = $replacement;
            }
        }

        return $replacements;
    }

    /**
     * Follows the chain the way GeneralUtility::getClassName() does, guarded against cycles.
     *
     * @param array<string, string> $replacements
     */
    private function effectiveClass(string $original, array $replacements): string
    {
        $class = $original;
        $seen = [];
        while (isset($replacements[$class]) && !isset($seen[$class])) {
            $seen[$class] = true;
            $class = $replacements[$class];
        }

        return $class;
    }

    private function ownerResolver(): ClassOwnerResolver
    {
        $packages = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $packages[] = [
                'extension' => $package->getPackageKey(),
                'package' => Cast::string($package->getValueFromComposerManifest('name')),
                'path' => $package->getPackagePath(),
                'namespaces' => $this->psr4Namespaces($package),
            ];
        }

        return new ClassOwnerResolver($packages, $this->findFile(...));
    }

    /**
     * @return list<string>
     */
    private function psr4Namespaces(PackageInterface $package): array
    {
        $autoload = json_decode((string) json_encode($package->getValueFromComposerManifest('autoload')), true);
        $psr4 = Cast::array(Cast::array($autoload)['psr-4'] ?? null);

        return array_values(array_filter(array_map(strval(...), array_keys($psr4)), static fn (string $namespace): bool => '' !== $namespace));
    }

    private function findFile(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);
            if (is_string($file)) {
                return $file;
            }
        }

        return null;
    }
}
