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

use KonradMichalik\Typo3AiMate\Service\RouteResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * RouteCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:route:resolve',
    description: 'Resolve a frontend URL to its redirect, site, language, page and route arguments as JSON.',
)]
final class RouteCommand extends AbstractJsonCommand
{
    public function __construct(private readonly RouteResolver $routeResolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Absolute URL, or a path resolved against the configured site bases');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->emit($output, $this->routeResolver->resolve(Cast::string($input->getArgument('url'))));
    }
}
