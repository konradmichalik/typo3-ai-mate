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

use Composer\Semver\VersionParser;
use KonradMichalik\Typo3AiMate\Support\{Cast, OwnPackages};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};
use UnexpectedValueException;

use function count;
use function is_object;
use function sprintf;

/**
 * UpgradeCompatibilityCommand.
 *
 * Offline check of every active non-core extension's typo3/cms-core
 * constraint against a target TYPO3 version. Reads installed package
 * metadata only, never a package registry.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:upgrade:compatibility',
    description: 'Check the typo3/cms-core constraint of every non-core extension against a target TYPO3 version as JSON.',
)]
final class UpgradeCompatibilityCommand extends AbstractJsonCommand
{
    private const STATUS_ORDER = ['blocking' => 0, 'unknown' => 1, 'compatible' => 2];

    private readonly VersionParser $versionParser;

    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
        $this->versionParser = new VersionParser();
    }

    /**
     * "14.3" covers every 14.3 patch release, "14.3.7" exactly that release.
     */
    public function targetConstraint(string $target): ?string
    {
        $target = trim($target);
        if (1 === preg_match('/^\d+\.\d+$/', $target)) {
            return $target.'.*';
        }

        return 1 === preg_match('/^\d+\.\d+\.\d+$/', $target) ? $target : null;
    }

    /**
     * Translate an ext_emconf.php "min-max" range into a Composer constraint.
     * An empty bound or 0.0.0 means unbounded, a single version is a minimum.
     */
    public function emConfConstraint(string $range): ?string
    {
        if (1 !== preg_match('/^\s*([\d.]*)\s*(?:-\s*([\d.]*))?\s*$/', $range, $matches)) {
            return null;
        }
        $minimum = $matches[1];
        $maximum = $matches[2] ?? '';
        $bounds = [];
        if ($this->isBound($minimum)) {
            $bounds[] = '>='.$minimum;
        }
        if ($this->isBound($maximum)) {
            $bounds[] = '<='.$maximum;
        }

        return [] === $bounds ? null : implode(' ', $bounds);
    }

    /**
     * Whether the constraint admits at least one version within the target.
     *
     * @return array{status: 'blocking'|'compatible'|'unknown', reason?: string}
     */
    public function evaluate(?string $constraint, string $targetConstraint): array
    {
        if (null === $constraint) {
            return ['status' => 'unknown', 'reason' => 'Neither composer.json nor ext_emconf.php declares a typo3/cms-core constraint.'];
        }

        try {
            $allows = $this->versionParser->parseConstraints($constraint)
                ->matches($this->versionParser->parseConstraints($targetConstraint));
        } catch (UnexpectedValueException) {
            return ['status' => 'unknown', 'reason' => sprintf('The constraint "%s" could not be parsed.', $constraint)];
        }

        return ['status' => $allows ? 'compatible' : 'blocking'];
    }

    /**
     * Blocking extensions first, then unknown, then compatible; own before
     * third-party within each status.
     *
     * @param list<array<string, mixed>> $extensions
     *
     * @return list<array<string, mixed>>
     */
    public function sort(array $extensions): array
    {
        usort($extensions, static fn (array $a, array $b): int => [
            self::STATUS_ORDER[Cast::string($a['status'] ?? '')] ?? 1,
            'own' === ($a['origin'] ?? '') ? 0 : 1,
            Cast::string($a['extension'] ?? ''),
        ] <=> [
            self::STATUS_ORDER[Cast::string($b['status'] ?? '')] ?? 1,
            'own' === ($b['origin'] ?? '') ? 0 : 1,
            Cast::string($b['extension'] ?? ''),
        ]);

        return $extensions;
    }

    protected function configure(): void
    {
        $this->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target TYPO3 version, major.minor (14.3) or a full version (14.3.7)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = trim(Cast::string($input->getOption('target')));
        $targetConstraint = $this->targetConstraint($target);
        if (null === $targetConstraint) {
            return $this->emit(
                $output,
                ['error' => sprintf('Invalid target "%s". Pass major.minor (e.g. 14.3) or a full version (e.g. 14.3.7).', $target)],
                Command::FAILURE,
            );
        }

        $extensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            if ('typo3-cms-extension' === $package->getValueFromComposerManifest('type')) {
                $extensions[] = $this->describe($package, $targetConstraint);
            }
        }
        $extensions = $this->sort($extensions);

        return $this->emit($output, [
            'target' => $target,
            'summary' => $this->summarize($extensions),
            'extensions' => $extensions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PackageInterface $package, string $targetConstraint): array
    {
        [$constraint, $source] = $this->coreConstraint($package);

        return [
            'extension' => $package->getPackageKey(),
            'package' => Cast::string($package->getValueFromComposerManifest('name')),
            'origin' => OwnPackages::origin($package->getPackagePath()),
            'constraint' => $constraint,
            'source' => $source,
            ...$this->evaluateDeclared($constraint, $source, $targetConstraint),
        ];
    }

    /**
     * The declared constraint as written, and where it came from. composer.json
     * wins; ext_emconf.php is the fallback for packages that do not require
     * typo3/cms-core there.
     *
     * @return array{0: string|null, 1: 'composer.json'|'ext_emconf.php'|null}
     */
    private function coreConstraint(PackageInterface $package): array
    {
        $require = $package->getValueFromComposerManifest('require');
        $composer = is_object($require) ? Cast::string($require->{'typo3/cms-core'} ?? '') : '';
        if ('' !== trim($composer)) {
            return [$composer, 'composer.json'];
        }

        $emConf = $this->emConfTypo3Range($package);

        return null !== $emConf ? [$emConf, 'ext_emconf.php'] : [null, null];
    }

    /**
     * @return array{status: 'blocking'|'compatible'|'unknown', reason?: string}
     */
    private function evaluateDeclared(?string $constraint, ?string $source, string $targetConstraint): array
    {
        if ('ext_emconf.php' !== $source) {
            return $this->evaluate($constraint, $targetConstraint);
        }
        $translated = $this->emConfConstraint(Cast::string($constraint));
        if (null === $translated) {
            return ['status' => 'unknown', 'reason' => sprintf('The ext_emconf.php range "%s" sets no version bound.', $constraint)];
        }

        return $this->evaluate($translated, $targetConstraint);
    }

    /**
     * Read constraints.depends.typo3 the way the core's PackageManager loads
     * ext_emconf.php: an include with $_EXTKEY set.
     */
    private function emConfTypo3Range(PackageInterface $package): ?string
    {
        $file = $package->getPackagePath().'ext_emconf.php';
        if (!is_file($file)) {
            return null;
        }
        $configuration = (static function (string $_EXTKEY, string $file): mixed {
            include $file;

            return Cast::array(get_defined_vars()['EM_CONF'] ?? null)[$_EXTKEY] ?? null;
        })($package->getPackageKey(), $file);
        $depends = Cast::array(Cast::array(Cast::array($configuration)['constraints'] ?? null)['depends'] ?? null);
        $range = trim(Cast::string($depends['typo3'] ?? ''));

        return '' !== $range ? $range : null;
    }

    /**
     * @param list<array<string, mixed>> $extensions
     *
     * @return array{blocking: int, unknown: int, compatible: int}
     */
    private function summarize(array $extensions): array
    {
        $statuses = array_column($extensions, 'status');

        return [
            'blocking' => count(array_keys($statuses, 'blocking', true)),
            'unknown' => count(array_keys($statuses, 'unknown', true)),
            'compatible' => count(array_keys($statuses, 'compatible', true)),
        ];
    }

    private function isBound(string $version): bool
    {
        return '' !== $version && '0.0.0' !== $version;
    }
}
