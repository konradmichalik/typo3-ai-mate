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

use KonradMichalik\Typo3AiMate\Service\FluidResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * FluidResolveCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:fluid:resolve',
    description: 'Resolve the Fluid template/partial/layout root-path override chain and the winning file as JSON.',
)]
final class FluidResolveCommand extends AbstractJsonCommand
{
    public function __construct(private readonly FluidResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::REQUIRED, 'Page UID (root paths come from the resolved TypoScript)')
            ->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'TypoScript path to the view config, e.g. plugin.tx_news_pi1 or page.10')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Template name to resolve, e.g. News/List')
            ->addOption('partial', null, InputOption::VALUE_REQUIRED, 'Partial name to resolve')
            ->addOption('layout', null, InputOption::VALUE_REQUIRED, 'Layout name to resolve')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'File format', 'html');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $viewPath = Cast::string($input->getOption('plugin'));
        if ('' === $viewPath) {
            return $this->emit($output, ['error' => '--plugin (a TypoScript view path, e.g. plugin.tx_news_pi1) is required.'], Command::FAILURE);
        }

        try {
            $data = $this->resolver->resolve(
                Cast::int($input->getArgument('pageId')),
                $viewPath,
                $this->option($input, 'template'),
                $this->option($input, 'partial'),
                $this->option($input, 'layout'),
                Cast::string($input->getOption('format')) ?: 'html',
            );
        } catch (Throwable $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        return $this->emit($output, $data);
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = Cast::string($input->getOption($name));

        return '' !== $value ? $value : null;
    }
}
