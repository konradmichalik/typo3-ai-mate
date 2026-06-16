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

use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\{BootService, Bootstrap};
use TYPO3\CMS\Core\Service\UpgradeWizardsService;

/**
 * UpgradeWizardsCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:upgrade:wizards',
    description: 'List all upgrade wizards (pending and done) with status as JSON.',
)]
final class UpgradeWizardsCommand extends AbstractJsonCommand
{
    public function __construct(private readonly BootService $bootService)
    {
        parent::__construct();
    }

    /**
     * @param array<mixed> $info result of UpgradeWizardsService::getWizardInformationByIdentifier()
     *
     * @return array{identifier: string, title: string, description: string, status: string, updateNecessary: bool}
     */
    public function formatWizard(string $identifier, array $info, bool $done): array
    {
        return [
            'identifier' => $identifier,
            'title' => Cast::string($info['title'] ?? ''),
            'description' => Cast::string($info['explanation'] ?? ''),
            'status' => $done ? 'DONE' : 'AVAILABLE',
            'updateNecessary' => (bool) ($info['shouldRenderWizard'] ?? false),
        ];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = $this->bootService
            ->loadExtLocalconfDatabaseAndExtTables(false, false)
            ->get(UpgradeWizardsService::class);
        Bootstrap::initializeBackendAuthentication();

        $wizards = [];
        foreach ($service->getUpgradeWizardIdentifiers() as $identifier) {
            $identifier = Cast::string($identifier);
            $info = Cast::array($service->getWizardInformationByIdentifier($identifier));
            $wizards[] = $this->formatWizard($identifier, $info, $service->isWizardDone($identifier));
        }

        return $this->emit($output, ['wizards' => $wizards]);
    }
}
