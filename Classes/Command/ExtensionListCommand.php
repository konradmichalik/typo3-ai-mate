<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * ExtensionListCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
#[AsCommand(
    name: 'typo3-ai-mate:extensions:list',
    description: 'Active extensions including key, version and description as JSON.',
)]
final class ExtensionListCommand extends Command
{
    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $metaData = $package->getPackageMetaData();
            $extensions[] = [
                'key' => $package->getPackageKey(),
                'version' => $metaData->getVersion(),
                'description' => $metaData->getDescription(),
            ];
        }

        usort($extensions, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $json = json_encode($extensions, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $output->writeln(false === $json ? '{"error":"Failed to encode JSON."}' : $json);

        return Command::SUCCESS;
    }
}
