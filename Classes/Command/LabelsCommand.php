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

use Exception;
use FilesystemIterator;
use KonradMichalik\Typo3AiMate\Command\Support\{IconLookup, LabelLookup};
use KonradMichalik\Typo3AiMate\Support\Cast;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\{LocalizationFactory, TranslationDomainMapper};
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function count;
use function sprintf;
use function strlen;

/**
 * LabelsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:labels:lookup',
    description: 'Whether label references resolve to a defined key, with the default text and optionally a translation, or the label files of an extension, as JSON.',
)]
final class LabelsCommand extends AbstractJsonCommand
{
    private const VALUE_LENGTH = 200;

    /**
     * The folders core's v14 LabelFileResolver searches recursively for label
     * files; v13 has no such listing, so both majors use the same folders.
     */
    private const LABEL_DIRECTORIES = [
        'Resources/Private/Language',
        'Configuration/Sets',
    ];

    /**
     * @param object|null $translationDomainMapper TranslationDomainMapper on v14, wired in Services.yaml as an optional reference because the class does not exist on v13
     */
    public function __construct(
        private readonly LocalizationFactory $localizationFactory,
        private readonly PackageManager $packageManager,
        private readonly Typo3Version $typo3Version,
        private readonly ?object $translationDomainMapper = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('references', null, InputOption::VALUE_REQUIRED, 'Comma-separated label references, e.g. LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:plugin.title (v14 also core.common:cancel)');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Also report whether each label is translated into this locale, e.g. de');
        $this->addOption('extension', null, InputOption::VALUE_REQUIRED, 'List the label files of this extension instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locale = trim(Cast::string($input->getOption('locale')));
        if ('' !== $locale && !LabelLookup::isValidLocale($locale)) {
            return $this->emit($output, ['error' => sprintf('"%s" is not a locale. Pass a language code such as de or de_CH.', $locale)], Command::FAILURE);
        }
        // Translation files are named de_CH.locallang.xlf, never de-CH.
        $locale = str_replace('-', '_', $locale);

        $references = IconLookup::parseList($input->getOption('references'));
        if ([] !== $references) {
            $described = [];
            foreach ($references as $reference) {
                $described[$reference] = $this->describe($reference, $locale);
            }

            return $this->emit($output, ['checked' => count($references), 'references' => $described]);
        }

        $extension = trim(Cast::string($input->getOption('extension')));
        if ('' !== $extension) {
            if (!$this->packageManager->isPackageActive($extension)) {
                return $this->emit($output, ['error' => sprintf('Extension "%s" is not loaded.', $extension)], Command::FAILURE);
            }

            return $this->emit($output, [
                'extension' => $extension,
                'files' => $this->labelFiles($extension),
                '_hint' => 'keys counts the default-language labels of each file, locales lists the translation files next to it.',
            ]);
        }

        return $this->emit($output, [
            '_hint' => 'Pass references=<LLL:EXT:…:key,…> to check label references (add locale=<de> to check their translation), or extension=<key> to list its label files.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $reference, string $locale): array
    {
        $parsed = LabelLookup::parseReference($reference);
        $extension = null !== $parsed ? LabelLookup::extensionKey($parsed['file']) : null;
        if (null === $parsed || null === $extension) {
            return [
                'valid' => false,
                '_hint' => 'Not a label reference. Expected LLL:EXT:<extension>/<path>.xlf:<key>'.($this->usesTranslationDomains() ? ' or <domain>:<key>.' : '.'),
            ];
        }
        if ($parsed['domain'] && !$this->usesTranslationDomains()) {
            return [
                'exists' => false,
                '_hint' => 'Translation domain references such as core.common:cancel exist since TYPO3 v14. Use LLL:EXT:<extension>/<path>.xlf:<key> on this installation.',
            ];
        }

        if (!$this->packageManager->isPackageActive($extension)) {
            return ['exists' => false, '_hint' => sprintf('Extension "%s" is not loaded, so none of its labels resolve.', $extension)];
        }

        return $this->describeInFile($parsed['file'], $parsed['key'], $extension, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeInFile(string $reference, string $key, string $extension, string $locale): array
    {
        $file = $this->mapToFile($reference);
        try {
            $labels = $this->labels($reference, 'default');
        } catch (Exception $exception) {
            return ['exists' => false, 'file' => $file, 'error' => $exception->getMessage()];
        }

        if ([] === $labels) {
            return $this->missingFile($file, $extension);
        }

        if (!isset($labels[$key])) {
            return [
                'exists' => false,
                'file' => $file,
                'suggestions' => IconLookup::suggest($key, array_keys($labels)),
                '_hint' => sprintf('"%s" is not defined in %s. An LLL: reference to it renders as an empty string.', $key, $file),
            ];
        }

        $described = ['exists' => true, 'file' => $file, 'default' => $this->shorten($labels[$key])];
        if ('' !== $locale) {
            $described['translation'] = $this->translation($reference, $key, $locale, $labels[$key]);
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function missingFile(string $file, string $extension): array
    {
        $fileExists = is_file(GeneralUtility::getFileAbsFileName($file));

        return [
            'exists' => false,
            'fileExists' => $fileExists,
            'labelFiles' => array_keys($this->labelFiles($extension)),
            '_hint' => sprintf(
                '%s %s. labelFiles lists the label files extension "%s" has.',
                $file,
                $fileExists ? 'defines no labels' : 'does not exist',
                $extension,
            ),
        ];
    }

    /**
     * A label counts as translated when the locale yields a text other than the
     * default one. Both major versions fall back to the default text for a key
     * the locale lacks, so a translation identical to the source is
     * indistinguishable from none.
     *
     * @return array<string, mixed>
     */
    private function translation(string $file, string $key, string $locale, string $default): array
    {
        try {
            $value = $this->labels($file, $locale)[$key] ?? $default;
        } catch (Exception $exception) {
            return ['locale' => $locale, 'error' => $exception->getMessage()];
        }
        $translated = $value !== $default;

        return array_filter([
            'locale' => $locale,
            'translated' => $translated,
            'value' => $this->shorten($value),
            '_hint' => $translated ? null : sprintf('Falls back to the default text: no "%s" translation, or one identical to it.', $locale),
        ], static fn (mixed $entry): bool => null !== $entry);
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $file, string $locale): array
    {
        $parsed = $this->localizationFactory->getParsedData($file, $locale);

        return $this->usesTranslationDomains()
            ? LabelLookup::flatten($parsed)
            : LabelLookup::flattenLegacy($parsed, $locale);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function labelFiles(string $extension): array
    {
        $packagePath = $this->packageManager->getPackage($extension)->getPackagePath();
        $domains = $this->domains($extension);
        $described = [];
        foreach (LabelLookup::groupTranslations($this->labelFilePaths($packagePath)) as $path => $locales) {
            $reference = 'EXT:'.$extension.'/'.$path;
            // One unparsable file must not take the whole listing down with it.
            try {
                $content = ['keys' => count($this->labels($reference, 'default'))];
            } catch (Exception $exception) {
                $content = ['error' => $exception->getMessage()];
            }
            $described[$reference] = array_filter(
                [...$content, 'locales' => $locales, 'domain' => $domains[$reference] ?? null],
                static fn (mixed $entry): bool => null !== $entry,
            );
        }
        ksort($described);

        return $described;
    }

    /**
     * @return list<string> paths relative to the package
     */
    private function labelFilePaths(string $packagePath): array
    {
        $paths = [];
        foreach (self::LABEL_DIRECTORIES as $directory) {
            if (!is_dir($packagePath.$directory)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packagePath.$directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && 'xlf' === $file->getExtension()) {
                    $paths[] = substr($file->getPathname(), strlen($packagePath));
                }
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string, string> file reference => translation domain
     */
    private function domains(string $extension): array
    {
        if (!$this->translationDomainMapper instanceof TranslationDomainMapper) {
            return [];
        }

        $domains = [];
        foreach ($this->translationDomainMapper->findLabelResourcesInPackage($extension) as $domain => $file) {
            $domains[Cast::string($file)] = (string) $domain;
        }

        return $domains;
    }

    private function mapToFile(string $file): string
    {
        if (!$this->translationDomainMapper instanceof TranslationDomainMapper) {
            return $file;
        }

        return $this->translationDomainMapper->mapDomainToFileName($file);
    }

    private function usesTranslationDomains(): bool
    {
        return $this->typo3Version->getMajorVersion() >= 14;
    }

    private function shorten(string $value): string
    {
        return mb_strlen($value) > self::VALUE_LENGTH ? mb_substr($value, 0, self::VALUE_LENGTH).'…' : $value;
    }
}
