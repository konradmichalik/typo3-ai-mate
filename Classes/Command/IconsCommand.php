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

use KonradMichalik\Typo3AiMate\Command\Support\IconLookup;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Imaging\IconRegistry;

use function count;
use function sprintf;

/**
 * IconsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:icons:lookup',
    description: 'Whether icon identifiers are registered, which extension provides them, or an overview of the registered identifier groups, as JSON.',
)]
final class IconsCommand extends AbstractJsonCommand
{
    public function __construct(private readonly IconRegistry $iconRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('identifiers', null, InputOption::VALUE_REQUIRED, 'Comma-separated icon identifiers to check, e.g. actions-add,my-ext-icon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $registered */
        $registered = array_values(array_map(
            static fn (mixed $identifier): string => Cast::string($identifier),
            $this->iconRegistry->getAllRegisteredIconIdentifiers(),
        ));

        $identifiers = IconLookup::parseList($input->getOption('identifiers'));
        if ([] !== $identifiers) {
            return $this->emit($output, [
                'checked' => count($identifiers),
                'identifiers' => $this->describe($identifiers, $registered),
            ]);
        }

        return $this->emit($output, [
            'count' => count($registered),
            'groups' => IconLookup::groupCounts($registered),
            '_hint' => 'groups counts the registered identifiers by their leading segment. Pass identifiers=<a,b> to check exact identifiers; a miss carries the closest registered ones as suggestions.',
        ]);
    }

    /**
     * @param list<string> $identifiers
     * @param list<string> $registered
     *
     * @return array<string, array<string, mixed>>
     */
    private function describe(array $identifiers, array $registered): array
    {
        $described = [];
        foreach ($identifiers as $identifier) {
            if (!$this->iconRegistry->isRegistered($identifier)) {
                $described[$identifier] = [
                    'registered' => false,
                    'suggestions' => IconLookup::suggest($identifier, $registered),
                    '_hint' => sprintf('"%s" is not registered. It resolves to no icon at all, not to a placeholder.', $identifier),
                ];
                continue;
            }

            $configuration = Cast::array($this->iconRegistry->getIconConfigurationByIdentifier($identifier));
            $source = IconLookup::source($configuration);
            $described[$identifier] = array_filter([
                'registered' => true,
                'providedBy' => IconLookup::providingExtension($source),
                'provider' => IconLookup::providerName(Cast::string($configuration['provider'] ?? '')),
                'source' => $source,
                'deprecated' => $this->iconRegistry->isDeprecated($identifier) ?: null,
            ], static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return $described;
    }
}
