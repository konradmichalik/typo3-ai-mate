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

use KonradMichalik\Typo3AiMate\Support\{Cast, TypoScriptTree};
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_string;

/**
 * TsConfigCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:tsconfig:dump',
    description: 'Resolved Page TSconfig (rootline-merged) or User TSconfig as JSON.',
)]
final class TsConfigCommand extends AbstractJsonCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::REQUIRED, 'Page UID (Page TSconfig accumulates down the rootline)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'page|user', 'page')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'BE user UID (required for --type=user)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Dotted scope, e.g. mod.web_layout / TCEFORM.pages / RTE.default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $tree = 'user' === $input->getOption('type')
                ? $this->resolveUserTsConfig(Cast::int($input->getOption('user')))
                : BackendUtility::getPagesTSconfig(Cast::int($input->getArgument('pageId')));
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        $path = $input->getOption('path');
        if (is_string($path) && '' !== $path) {
            $tree = TypoScriptTree::scope($tree, $path);
        }

        return $this->emit($output, $tree);
    }

    /**
     * @return array<mixed>
     */
    private function resolveUserTsConfig(int $userUid): array
    {
        if ($userUid <= 0) {
            throw new RuntimeException('--type=user requires a --user <uid>.', 1730000001);
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($userUid);
        $backendUser->fetchGroupData();

        return $backendUser->getTSConfig();
    }
}
