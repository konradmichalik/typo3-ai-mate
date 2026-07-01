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

use KonradMichalik\Typo3AiMate\Service\TypoScriptResolver;
use KonradMichalik\Typo3AiMate\Support\{Cast, TypoScriptTree};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;

/**
 * TypoScriptCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:typoscript:dump',
    description: 'Resolved frontend TypoScript (setup|constants) of a page as JSON.',
)]
final class TypoScriptCommand extends AbstractJsonCommand
{
    public function __construct(private readonly TypoScriptResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::REQUIRED, 'Page UID (TypoScript is page-context dependent)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'setup|constants', 'setup')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Dotted path to scope the output, e.g. lib.foo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageId = Cast::int($input->getArgument('pageId'));
        $type = 'constants' === $input->getOption('type') ? 'constants' : 'setup';

        try {
            $tree = 'constants' === $type
                ? $this->resolver->resolveConstants($pageId)
                : $this->resolver->resolveSetup($pageId);
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        $path = $input->getOption('path');
        if (is_string($path) && '' !== $path) {
            $tree = TypoScriptTree::scope($tree, $path);
        }

        return $this->emit($output, $tree);
    }
}
