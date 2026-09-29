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

use KonradMichalik\Typo3AiMate\Command\Support\{ConfigLayerResolver, ConfigRedactor};
use KonradMichalik\Typo3AiMate\Mcp\Enum\ConfigSection;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;
use function explode;
use function is_array;
use function is_file;
use function sort;
use function sprintf;
use function strtolower;
use function trim;

/**
 * ConfigCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:config:dump',
    description: 'TYPO3_CONF_VARS, feature toggles or extension configuration (secrets masked) as JSON.',
)]
final class ConfigCommand extends AbstractJsonCommand
{
    /**
     * Walk a slash-separated path into a config subtree.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: bool, 1: mixed} whether the full path resolved, and its value
     */
    public function traverse(array $data, string $path): array
    {
        $current = $data;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' === $segment || !is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }

        return [true, $current];
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Slash-separated path scoped to --section: e.g. FE or SYS/features for confvars (default); a feature toggle name (e.g. security.frontend.enforceContentSecurityPolicy) for features; an extension key, optionally with a sub-path, for extension')
            ->addOption('section', null, InputOption::VALUE_REQUIRED, 'confvars (default) | features | extension');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $section = ConfigSection::tryFrom(strtolower(trim(Cast::string($input->getOption('section'))))) ?? ConfigSection::Confvars;
        $rawPath = Cast::string($input->getOption('path'));
        $path = '' !== trim($rawPath, '/') ? trim($rawPath, '/') : null;

        /** @var array<string, mixed> $confVars */
        $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        /** @var array<string, mixed> $features */
        $features = Cast::array(Cast::array($confVars['SYS'] ?? null)['features'] ?? null);

        if (ConfigSection::Features === $section) {
            return null === $path
                ? $this->emit($output, ['features' => ConfigRedactor::redact($features)])
                : $this->emitScoped($output, $features, $path, 'Unknown feature toggle path "%s".', $confVars, 'SYS/features/'.$path);
        }

        if (ConfigSection::Extension === $section) {
            /** @var array<string, mixed> $extensions */
            $extensions = Cast::array($confVars['EXTENSIONS'] ?? null);
            if (null === $path) {
                $keys = array_keys($extensions);
                sort($keys);

                return $this->emit($output, ['extensions' => $keys]);
            }

            return $this->emitScoped($output, $extensions, $path, 'Unknown extension configuration path "%s".', $confVars, 'EXTENSIONS/'.$path);
        }

        if (null === $path) {
            return $this->emit($output, [
                'keys' => array_keys($confVars),
                'features' => ConfigRedactor::redact($features),
            ]);
        }

        return $this->emitScoped($output, $confVars, $path, 'Unknown configuration path "%s".', $confVars, $path);
    }

    /**
     * @param array<string, mixed> $data         the subtree --path is resolved against for the displayed value
     * @param array<string, mixed> $confVars     the full live $GLOBALS['TYPO3_CONF_VARS'], for source attribution
     * @param string               $absolutePath the same value rooted at $confVars, since --section can scope
     *                                           $data/$path to a subtree (SYS/features/…, EXTENSIONS/…)
     */
    private function emitScoped(OutputInterface $output, array $data, string $path, string $errorFormat, array $confVars, string $absolutePath): int
    {
        [$found, $value] = $this->traverse($data, $path);
        if (!$found) {
            return $this->emit($output, ['error' => sprintf($errorFormat, $path)], Command::FAILURE);
        }

        // traverse() returns the bare value, so a sensitive *leaf* key (e.g.
        // requesting path=SYS/encryptionKey directly) has no key left to match
        // against by the time redact() sees it. Re-attach the requested key one
        // last time so the same key-based check that protects a parent's
        // children also protects the exact path asked for.
        $segments = explode('/', $path);
        $requestedKey = end($segments);
        /** @var array<string, mixed> $masked */
        $masked = ConfigRedactor::redact([$requestedKey => $value]);

        $result = ['path' => $path, 'value' => $masked[$requestedKey]];
        $attribution = $this->resolveSource($absolutePath, $confVars);
        if (null !== $attribution) {
            $result += $attribution;
        }

        return $this->emit($output, $result);
    }

    /**
     * Best-effort: resolving EXT:core/… and the project's config path both need a booted TYPO3
     * (package manager, Environment::initialize()). A unit test that constructs this command
     * directly, without that boot, must still get the core path/value answer, so a failure here
     * degrades to no attribution rather than failing the whole command.
     *
     * @param array<string, mixed> $confVars
     *
     * @return array{source: string, overrideChain: list<string>}|null
     */
    private function resolveSource(string $absolutePath, array $confVars): ?array
    {
        try {
            $defaultFile = GeneralUtility::getFileAbsFileName('EXT:core/Configuration/DefaultConfiguration.php');
            /** @var array<string, mixed> $default */
            $default = '' !== $defaultFile && is_file($defaultFile) ? require $defaultFile : [];

            $settingsFile = Environment::getConfigPath().'/system/settings.php';
            /** @var array<string, mixed>|null $settings */
            $settings = is_file($settingsFile) ? require $settingsFile : null;
        } catch (Throwable) {
            return null;
        }

        return (new ConfigLayerResolver($default, $settings))->resolve($absolutePath, $confVars);
    }
}
