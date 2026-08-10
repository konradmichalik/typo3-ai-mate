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

use Composer\InstalledVersions;
use JsonException;
use KonradMichalik\Typo3AiMate\Support\OwnPackages;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Schema\Field\StaticSelectFieldType;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Schema\{TcaSchema, TcaSchemaFactory};

use function is_array;
use function is_int;
use function sort;
use function time;

/**
 * InfoCommand.
 *
 * The session entry point: version/context/database facts, active
 * extensions, relevant package versions, profiler CLI availability and a
 * compact content-type inventory, so an assistant does not have to
 * reconstruct any of this from `composer.json` (which fails on constraint
 * ranges like `^13.4 || ^14.3`) or guess at it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:info:dump',
    description: 'Session entry point: TYPO3/PHP/database facts, extensions, package versions, profiler state and a content-type inventory, as JSON.',
)]
final class InfoCommand extends AbstractJsonCommand
{
    /**
     * Console commands this package's tools rely on being registered; used to
     * detect whether the profiler CLI is available in this instance.
     */
    private const PROFILER_ACTIVATE_COMMAND = 'profiler:activate';

    private const PROFILER_STATE_FILE = '/var/log/profiler-activation-state.json';

    /**
     * Packages an assistant needs the installed version of to reason about
     * compatibility. Deliberately curated, not the full dependency tree.
     */
    private const RELEVANT_PACKAGES = [
        'konradmichalik/typo3-ai-mate',
        'konradmichalik/typo3-request-profiler',
        'symfony/ai-mate',
        'mcp/sdk',
    ];

    public function __construct(
        private readonly Typo3Version $typo3Version,
        private readonly PackageManager $packageManager,
        private readonly ConnectionPool $connectionPool,
        private readonly CommandRegistry $commandRegistry,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    /**
     * @return array{platform: string, version: string}
     */
    public function describeDatabase(): array
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $platform = $connection->getDatabasePlatform();
        $platformName = preg_replace('/Platform$/', '', (new ReflectionClass($platform))->getShortName()) ?? '';

        return ['platform' => $platformName, 'version' => $connection->getServerVersion()];
    }

    /**
     * @return array{own: list<string>, thirdParty: list<string>}
     */
    public function describeExtensions(): array
    {
        $own = [];
        $thirdParty = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            if ('typo3-cms-extension' !== $package->getValueFromComposerManifest('type')) {
                continue;
            }
            if (OwnPackages::isOwn($package->getPackagePath())) {
                $own[] = $package->getPackageKey();
            } else {
                $thirdParty[] = $package->getPackageKey();
            }
        }
        sort($own);
        sort($thirdParty);

        return ['own' => $own, 'thirdParty' => $thirdParty];
    }

    /**
     * @return array<string, string>
     */
    public function describePackages(): array
    {
        $packages = [];
        foreach (self::RELEVANT_PACKAGES as $name) {
            if (!InstalledVersions::isInstalled($name)) {
                continue;
            }
            $version = InstalledVersions::getPrettyVersion($name);
            if (null !== $version) {
                $packages[$name] = $version;
            }
        }

        return $packages;
    }

    /**
     * @return array{cliAvailable: bool, version: string|null, active: bool}
     */
    public function describeProfiler(): array
    {
        $version = InstalledVersions::isInstalled('konradmichalik/typo3-request-profiler')
            ? InstalledVersions::getPrettyVersion('konradmichalik/typo3-request-profiler')
            : null;

        return [
            'cliAvailable' => $this->commandRegistry->has(self::PROFILER_ACTIVATE_COMMAND),
            'version' => $version,
            'active' => $this->isProfilerActivationWindowOpen(),
        ];
    }

    /**
     * @return array{value: int|string|null, label: string, group: string|null}
     */
    public function describeSelectItem(SelectItem $item, LanguageService $languageService): array
    {
        return [
            'value' => $item->getValue(),
            'label' => $languageService->sL($item->getLabel()),
            'group' => $item->getGroup(),
        ];
    }

    /**
     * @return list<array{value: int|string|null, label: string, group: string|null}>
     */
    public function describeSelectField(TcaSchema $schema, string $fieldName, LanguageService $languageService): array
    {
        if (!$schema->hasField($fieldName)) {
            return [];
        }
        $field = $schema->getField($fieldName);
        if (!$field instanceof StaticSelectFieldType) {
            return [];
        }

        $items = [];
        foreach ($field->getItems() as $item) {
            if ($item->isDivider()) {
                continue;
            }
            $items[] = $this->describeSelectItem($item, $languageService);
        }

        return $items;
    }

    /**
     * @return array{cTypes: list<array<string, mixed>>, listTypes?: list<array<string, mixed>>}
     */
    public function describeContentTypes(LanguageService $languageService): array
    {
        if (!$this->tcaSchemaFactory->has('tt_content')) {
            return ['cTypes' => []];
        }
        $schema = $this->tcaSchemaFactory->get('tt_content');

        $contentTypes = ['cTypes' => $this->describeSelectField($schema, 'CType', $languageService)];
        // list_type plugins are gone in v14; only report the section when the
        // column still exists rather than branching on the major version.
        if ($schema->hasField('list_type')) {
            $contentTypes['listTypes'] = $this->describeSelectField($schema, 'list_type', $languageService);
        }

        return $contentTypes;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->emit($output, [
            'typo3' => [
                'version' => $this->typo3Version->getVersion(),
                'majorVersion' => $this->typo3Version->getMajorVersion(),
            ],
            'php' => \PHP_VERSION,
            'context' => (string) Environment::getContext(),
            'database' => $this->describeDatabase(),
            'extensions' => $this->describeExtensions(),
            'packages' => $this->describePackages(),
            'profiler' => $this->describeProfiler(),
            'contentTypes' => $this->describeContentTypes($this->languageServiceFactory->create('en')),
        ]);
    }

    /**
     * The time-boxed activation window only (same caveat as
     * `typo3-profiler-status`): profiling can also be on via the Development
     * context or a per-request header, neither of which this reflects.
     */
    private function isProfilerActivationWindowOpen(): bool
    {
        $contents = @file_get_contents(Environment::getProjectPath().self::PROFILER_STATE_FILE);
        if (false === $contents) {
            return false;
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        $expiresAt = is_array($data) && is_int($data['expiresAt'] ?? null) ? $data['expiresAt'] : null;

        return null !== $expiresAt && $expiresAt > time();
    }
}
